<?php

namespace Pharaonic\Laravel\Settings\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Pharaonic\Laravel\Settings\Enums\CastingPolicy;
use Pharaonic\Laravel\Settings\Enums\SettingType;
use Pharaonic\Laravel\Settings\Models\Setting;
use Pharaonic\Laravel\Settings\Support\Definition;
use Pharaonic\Laravel\Settings\Support\Identifier;
use Pharaonic\Laravel\Settings\Support\ScopeCache;
use Pharaonic\Laravel\Settings\Support\TypeNormalizer;
use Pharaonic\Laravel\Settings\Support\ValueCodec;
use RuntimeException;
use Throwable;

/**
 * Converts a pharaonic/laravel-settings 1.x table (name, value,
 * settingable_type, settingable_id) to the 2 schema, side by side: the 1.x
 * table is renamed to a backup and kept, a new table is created and filled,
 * and the result is verified. Without --apply nothing changes.
 */
final class MigrateLegacySettingsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'settings:migrate-legacy
        {--apply : Convert the table; without it the command only reports what it would do}
        {--duplicates=fail : A key stored twice in one scope: fail, or latest to keep the most recently updated row}
        {--ambiguous=fail : A value that may be JSON or text: fail, string to keep the text, or decode to store the JSON array}
        {--map=* : The identifier prefix of a legacy settingable_type, as "App\\User=user"}
        {--backup= : The name the 1.x table is renamed to (default: the table name with "_legacy")}';

    /**
     * @var string
     */
    protected $description = 'Report on, and with --apply convert, a pharaonic/laravel-settings 1.x settings table';

    /**
     * The problems that block the conversion.
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string}>
     */
    private array $problems = [];

    /**
     * The values that may be JSON or text, and what happens to them.
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string, 4: string}>
     */
    private array $ambiguous = [];

    public function handle(): int
    {
        // Artisan reuses the command instance between calls.
        $this->problems = [];
        $this->ambiguous = [];

        $duplicates = $this->stringOption('duplicates');
        $ambiguous = $this->stringOption('ambiguous');

        if (! in_array($duplicates, ['fail', 'latest'], true) || ! in_array($ambiguous, ['fail', 'string', 'decode'], true)) {
            $this->error('--duplicates takes fail or latest, and --ambiguous takes fail, string or decode.');

            return 1;
        }

        $connection = $this->connection();
        $schema = $connection->getSchemaBuilder();
        $table = (new Setting)->getTable();
        $backup = $this->stringOption('backup') ?: $table.'_legacy';

        if (! $schema->hasTable($table)) {
            $this->error("The [{$table}] table does not exist; a fresh install only needs `php artisan migrate`.");

            return 1;
        }

        $columns = array_map(function ($column) {
            return strtolower(self::text($column));
        }, $schema->getColumnListing($table));

        if (array_diff(['identifier', 'key', 'type', 'nullable', 'value'], $columns) === []) {
            $this->info("The [{$table}] table already has the pharaonic/laravel-settings 2 schema; nothing to convert.");

            if ($schema->hasTable($backup)) {
                $this->line("The 1.x rows are kept in [{$backup}].");
            }

            return 0;
        }

        if (array_diff(['name', 'value', 'settingable_type', 'settingable_id'], $columns) !== []) {
            $this->error("The [{$table}] table has neither the 1.x nor the 2 schema of pharaonic/laravel-settings; it is left as it is.");

            return 1;
        }

        try {
            $map = $this->typeMap();
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return 1;
        }

        [$rows, $dropped, $read] = $this->plan($connection, $table, $map, $ambiguous, $duplicates);

        $this->report($rows, $read, $dropped);

        if ($this->problems !== []) {
            $this->error(count($this->problems).' row(s) block the conversion; nothing was changed. Fix them, or choose a policy with --duplicates, --ambiguous or --map.');

            return 1;
        }

        if (! $this->option('apply')) {
            $this->info('Dry run: nothing was changed. Run the command again with --apply to convert the table.');

            return 0;
        }

        if ($schema->hasTable($backup)) {
            $this->error("The backup table [{$backup}] already exists; choose another name with --backup.");

            return 1;
        }

        return $this->apply($connection, $table, $backup, $rows);
    }

    /**
     * The 2 rows the legacy rows become, keyed by identifier and key, with
     * the ids of the duplicates left out and the number of rows read.
     *
     * @param  array<string, string>  $map
     * @return array{0: array<string, array{identifier: string, key: string, type: string|null, nullable: bool, value: string, created_at: string, updated_at: string, id: string}>, 1: list<string>, 2: int}
     */
    private function plan(Connection $connection, string $table, array $map, string $ambiguous, string $duplicates): array
    {
        $dropped = [];
        $read = 0;
        $rows = [];
        $now = Carbon::now()->toDateTimeString();

        foreach ($connection->table($table)->orderBy('id')->get() as $legacy) {
            $read++;
            $legacy = (array) $legacy;
            $id = self::text($legacy['id'] ?? '');
            $name = self::text($legacy['name'] ?? '');

            $identifier = $this->identifier($id, $name, $legacy['settingable_type'] ?? null, $legacy['settingable_id'] ?? null, $map);

            if ($identifier === null) {
                continue;
            }

            try {
                Identifier::key($name);
            } catch (InvalidArgumentException $exception) {
                $this->problems[] = [$id, $identifier, $name, $exception->getMessage()];

                continue;
            }

            $converted = $this->value($id, $identifier, $name, $legacy['value'] ?? null, $ambiguous);

            if ($converted === null) {
                continue;
            }

            $row = [
                'identifier' => $identifier,
                'key' => $name,
                'type' => $converted[1],
                'nullable' => $converted[2],
                'value' => ValueCodec::encode($converted[0], $name, $identifier),
                'created_at' => self::text($legacy['created_at'] ?? null) ?: $now,
                'updated_at' => self::text($legacy['updated_at'] ?? null) ?: $now,
                'id' => $id,
            ];

            $slot = $identifier."\0".$name;

            if (! isset($rows[$slot])) {
                $rows[$slot] = $row;

                continue;
            }

            if ($duplicates === 'fail') {
                $this->problems[] = [$id, $identifier, $name, 'also stored by row '.$rows[$slot]['id'].' (--duplicates=latest keeps the most recently updated one)'];

                continue;
            }

            // The most recently updated row wins; the later id breaks a tie.
            if ($row['updated_at'] >= $rows[$slot]['updated_at']) {
                $dropped[] = $rows[$slot]['id'];
                $rows[$slot] = $row;
            } else {
                $dropped[] = $id;
            }
        }

        ksort($rows, SORT_STRING);

        return [$rows, $dropped, $read];
    }

    /**
     * The 2 identifier of a legacy row, or null after recording a problem.
     *
     * @param  array<string, string>  $map
     */
    private function identifier(string $id, string $name, mixed $type, mixed $key, array $map): ?string
    {
        $type = $type === null ? null : self::text($type);
        $key = $key === null ? null : self::text($key);

        if (($type === null || $type === '') && ($key === null || $key === '')) {
            return Setting::GLOBAL;
        }

        if ($type === null || $type === '' || $key === null || $key === '') {
            $this->problems[] = [$id, '?', $name, 'settingable_type and settingable_id must both be set or both be empty'];

            return null;
        }

        if (isset($map[$type])) {
            $prefix = $map[$type];
        } elseif (class_exists($type) && is_subclass_of($type, Model::class)) {
            $prefix = (new $type)->getMorphClass();
        } elseif (Relation::getMorphedModel($type) !== null) {
            $prefix = $type;
        } else {
            $this->problems[] = [$id, $type.':'.$key, $name, "the model type [{$type}] is not a class or morph alias; map it with --map=\"{$type}=prefix\""];

            return null;
        }

        try {
            return Identifier::check($prefix.':'.$key);
        } catch (InvalidArgumentException $exception) {
            $this->problems[] = [$id, $prefix.':'.$key, $name, $exception->getMessage()];

            return null;
        }
    }

    /**
     * The value, type and nullability of a legacy value, or null after
     * recording a problem.
     *
     * 1.x stored text: a string as it was, an array as JSON, and true, false
     * and numbers as PHP cast them to text ("1", "", "20"). It read a value
     * starting with "{" back as a JSON object and everything else as text,
     * so "[...]" and "{...}" may be JSON written by 1.x or text a user typed.
     *
     * @return array{0: mixed, 1: string|null, 2: bool}|null
     */
    private function value(string $id, string $identifier, string $key, mixed $raw, string $policy): ?array
    {
        $value = $raw === null ? null : self::text($raw);

        if (is_string($value) && preg_match('//u', $value) !== 1) {
            $this->problems[] = [$id, $identifier, $key, 'the value is not valid UTF-8'];

            return null;
        }

        if (is_string($value) && $value !== '' && ($value[0] === '{' || $value[0] === '[')) {
            $decoded = json_decode($value, true);
            $valid = json_last_error() === JSON_ERROR_NONE && is_array($decoded);
            $kind = $valid ? ($value[0] === '{' ? 'JSON object' : 'JSON array') : 'JSON-like text';

            if ($policy === 'fail') {
                $this->problems[] = [$id, $identifier, $key, "the value is {$kind}, which 1.x may have meant as text or JSON (--ambiguous=string or decode)"];

                return null;
            }

            if ($policy === 'decode' && ! $valid) {
                $this->problems[] = [$id, $identifier, $key, 'the value starts like JSON but is not valid JSON, so --ambiguous=decode cannot decode it (--ambiguous=string keeps the text)'];

                return null;
            }

            $this->ambiguous[] = [$id, $identifier, $key, $kind, $policy === 'decode' ? 'stored as an array' : 'stored as text'];
            $value = $policy === 'decode' ? $decoded : $value;
        }

        $definition = Definition::find($key);

        if ($definition === null) {
            // 1.x let every key hold null, so converted keys stay nullable.
            return [$value, SettingType::of($value)?->value, true];
        }

        if ($value === null) {
            if (! $definition->nullable) {
                $this->problems[] = [$id, $identifier, $key, 'the value is null, but the definition is not nullable'];

                return null;
            }

            return [null, $definition->type->value, true];
        }

        try {
            $value = TypeNormalizer::normalize($value, $definition->type, CastingPolicy::SAFE, $key, $identifier);
        } catch (InvalidArgumentException) {
            $this->problems[] = [$id, $identifier, $key, "the value cannot be safely converted to the defined type [{$definition->type->value}]"];

            return null;
        }

        return [$value, $definition->type->value, $definition->nullable];
    }

    /**
     * @param  array<string, array{identifier: string, key: string, type: string|null, nullable: bool, value: string, created_at: string, updated_at: string, id: string}>  $rows
     * @param  list<string>  $dropped
     */
    private function report(array $rows, int $read, array $dropped): void
    {
        $scopes = [];

        foreach ($rows as $row) {
            $scopes[$row['identifier']] = ($scopes[$row['identifier']] ?? 0) + 1;
        }

        ksort($scopes, SORT_STRING);

        $this->line("Legacy rows read: {$read}. Rows to write: ".count($rows).'. Scopes: '.count($scopes).'.');

        if ($scopes !== []) {
            $this->table(['Scope', 'Rows'], array_map(function ($identifier, $count) {
                return [$identifier, (string) $count];
            }, array_keys($scopes), $scopes));
        }

        if ($this->ambiguous !== []) {
            $this->warn('Values that may be JSON or text:');
            $this->table(['Legacy id', 'Scope', 'Key', 'Value', 'Result'], $this->ambiguous);
        }

        if ($dropped !== []) {
            sort($dropped, SORT_NATURAL);
            $this->warn('Duplicate rows left out (they stay in the backup table): '.implode(', ', $dropped).'.');
        }

        if ($this->problems !== []) {
            $this->table(['Legacy id', 'Scope', 'Key', 'Problem'], $this->problems);
        }
    }

    /**
     * @param  array<string, array{identifier: string, key: string, type: string|null, nullable: bool, value: string, created_at: string, updated_at: string, id: string}>  $rows
     */
    private function apply(Connection $connection, string $table, string $backup, array $rows): int
    {
        $schema = $connection->getSchemaBuilder();
        $schema->rename($table, $backup);

        try {
            $this->createTable($connection, $table);

            $connection->transaction(function () use ($connection, $table, $rows) {
                foreach (array_chunk(array_values($rows), 100) as $chunk) {
                    $connection->table($table)->insert(array_map(function (array $row) {
                        unset($row['id']);

                        return $row;
                    }, $chunk));
                }
            });

            $expected = self::checksum($rows);
            $written = self::checksum($this->written($connection, $table));

            if ($expected !== $written) {
                throw new RuntimeException("The rows read back from [{$table}] do not match the converted rows.");
            }
        } catch (Throwable $exception) {
            $schema->dropIfExists($table);
            $schema->rename($backup, $table);

            $this->error("The conversion failed and was undone: [{$backup}] is back as [{$table}].");

            throw $exception;
        }

        $cache = new ScopeCache;

        foreach (array_unique(array_column($rows, 'identifier')) as $identifier) {
            $cache->invalidate($connection, $table, $identifier);
        }

        $this->info('Converted '.count($rows)." rows into [{$table}]; the 1.x rows are kept in [{$backup}].");
        $this->line("Checksum: {$expected}.");
        $this->line("To undo: drop [{$table}] and rename [{$backup}] back to [{$table}].");

        return 0;
    }

    /**
     * The 2 schema, as the package migration creates it.
     */
    private function createTable(Connection $connection, string $table): void
    {
        $driver = $connection->getDriverName();
        $charset = $connection->getConfig('charset');

        $connection->getSchemaBuilder()->create($table, function (Blueprint $blueprint) use ($driver, $charset) {
            $blueprint->bigIncrements('id');
            $identifier = $blueprint->string('identifier', 191)->default(Setting::GLOBAL);
            $key = $blueprint->string('key', 191);
            $blueprint->string('type', 32)->nullable();
            $blueprint->boolean('nullable')->default(false);
            $blueprint->longText('value');
            $blueprint->timestamps();

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $collation = (is_string($charset) && $charset !== '' ? $charset : 'utf8mb4').'_bin';
                $identifier->collation($collation);
                $key->collation($collation);
            }

            $blueprint->unique(['identifier', 'key']);
        });
    }

    /**
     * @return list<array{identifier: string, key: string, type: string|null, nullable: bool, value: string}>
     */
    private function written(Connection $connection, string $table): array
    {
        $rows = [];

        foreach ($connection->table($table)->get(['identifier', 'key', 'type', 'nullable', 'value']) as $row) {
            $row = (array) $row;
            $rows[] = [
                'identifier' => self::text($row['identifier'] ?? ''),
                'key' => self::text($row['key'] ?? ''),
                'type' => isset($row['type']) ? self::text($row['type']) : null,
                'nullable' => (bool) ($row['nullable'] ?? false),
                'value' => self::text($row['value'] ?? ''),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<array-key, array{identifier: string, key: string, type: string|null, nullable: bool, value: string}>  $rows
     */
    private static function checksum(array $rows): string
    {
        $lines = array_map(function (array $row) {
            return implode("\0", [$row['identifier'], $row['key'], (string) $row['type'], $row['nullable'] ? '1' : '0', $row['value']]);
        }, array_values($rows));

        sort($lines, SORT_STRING);

        return sha1(implode("\n", $lines));
    }

    /**
     * @return array<string, string>
     */
    private function typeMap(): array
    {
        $map = [];
        $options = $this->option('map');

        foreach (is_array($options) ? $options : [] as $entry) {
            $parts = explode('=', self::text($entry), 2);

            if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
                throw new InvalidArgumentException('--map takes "LegacyType=prefix", such as --map="App\\User=user".');
            }

            $map[$parts[0]] = $parts[1];
        }

        return $map;
    }

    private function connection(): Connection
    {
        return (new Setting)->getConnection();
    }

    private function stringOption(string $name): string
    {
        $value = $this->option($name);

        return is_string($value) ? $value : '';
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
