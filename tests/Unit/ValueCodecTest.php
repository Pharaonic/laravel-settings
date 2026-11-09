<?php

namespace Pharaonic\Laravel\Settings\Tests\Unit;

use Pharaonic\Laravel\Settings\Exceptions\CorruptSettingValueException;
use Pharaonic\Laravel\Settings\Support\ValueCodec;
use Pharaonic\Laravel\Settings\Tests\TestCase;

final class ValueCodecTest extends TestCase
{
    /**
     * @dataProvider values
     */
    public function test_every_supported_value_round_trips_exactly(mixed $value, string $json): void
    {
        $this->assertSame($json, ValueCodec::encode($value, 'k', 's'));
        $this->assertSame($value, ValueCodec::decode($json, 'k', 's'));
    }

    /**
     * @return array<int, array{mixed, string}>
     */
    public static function values(): array
    {
        return [
            [null, 'null'],
            [true, 'true'],
            [0, '0'],
            [-12, '-12'],
            [1.0, '1.0'],
            [0.1, '0.1'],
            ['', '""'],
            ['مرحبا', '"مرحبا"'],
            ['{"a":1}', '"{\"a\":1}"'],
            [[], '[]'],
            [[1, 2], '[1,2]'],
            [['a' => ['b' => null]], '{"a":{"b":null}}'],
            [[1 => 'x'], '{"1":"x"}'],
        ];
    }

    public function test_stored_text_that_is_not_json_is_refused_without_echoing_it(): void
    {
        try {
            ValueCodec::decode('PSENC1:secret', 'api.token', 'tenant');
            $this->fail('No exception was thrown.');
        } catch (CorruptSettingValueException $exception) {
            $this->assertStringContainsString('[api.token]', $exception->getMessage());
            $this->assertStringNotContainsString('secret', $exception->getMessage());
        }
    }
}
