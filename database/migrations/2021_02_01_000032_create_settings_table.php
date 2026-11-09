<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per scope and key. The scope is the identifier column: "__global__"
 * for the global scope, a free-form name, or "{morph class}:{key}" for a
 * model, so the unique index alone keeps a key from being stored twice.
 *
 * This file keeps its 1.x name, so an application that already ran it never
 * runs it again: it upgrades the 1.x table with `php artisan
 * settings:migrate-legacy` instead.
 */
class CreateSettingsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $connection = config('pharaonic.settings.connection');
        $name = config('pharaonic.settings.table', 'settings');
        $schema = Schema::connection(is_string($connection) ? $connection : null);
        $name = is_string($name) && $name !== '' ? $name : 'settings';

        if ($schema->hasTable($name)) {
            throw new RuntimeException(sprintf(
                'The [%s] table already exists, so pharaonic/laravel-settings cannot create it. '
                .'A table from pharaonic/laravel-settings 1.x is upgraded with `php artisan settings:migrate-legacy`; '
                .'any other table needs pharaonic.settings.table set to another name.',
                $name
            ));
        }

        $driver = $schema->getConnection()->getDriverName();
        $charset = $schema->getConnection()->getConfig('charset');

        $schema->create($name, function (Blueprint $table) use ($driver, $charset) {
            $table->bigIncrements('id');
            $identifier = $table->string('identifier', 191)->default('__global__');
            $key = $table->string('key', 191);
            $table->string('type', 32)->nullable();
            $table->boolean('nullable')->default(false);
            $table->longText('value');
            $table->timestamps();

            // MySQL and MariaDB compare strings case-insensitively by default,
            // which would make "Theme" and "theme" one row.
            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $collation = (is_string($charset) && $charset !== '' ? $charset : 'utf8mb4').'_bin';
                $identifier->collation($collation);
                $key->collation($collation);
            }

            $table->unique(['identifier', 'key']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        $connection = config('pharaonic.settings.connection');
        $name = config('pharaonic.settings.table', 'settings');

        Schema::connection(is_string($connection) ? $connection : null)
            ->dropIfExists(is_string($name) && $name !== '' ? $name : 'settings');
    }
}
