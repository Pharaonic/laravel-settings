<?php

namespace Pharaonic\Laravel\Settings\Tests\Unit;

use Pharaonic\Laravel\Settings\Enums\CastingPolicy;
use Pharaonic\Laravel\Settings\Enums\SettingType;
use Pharaonic\Laravel\Settings\Exceptions\SettingTypeMismatchException;
use Pharaonic\Laravel\Settings\Support\TypeNormalizer;
use Pharaonic\Laravel\Settings\Tests\TestCase;

final class TypeNormalizerTest extends TestCase
{
    /**
     * @dataProvider exact
     */
    public function test_the_strict_policy_accepts_the_exact_type(mixed $value, string $type): void
    {
        $this->assertSame($value, TypeNormalizer::normalize($value, $type, CastingPolicy::STRICT, 'key', 'scope'));
    }

    /**
     * @return array<int, array{mixed, string}>
     */
    public static function exact(): array
    {
        return [
            [1, SettingType::INTEGER],
            [1.5, SettingType::FLOAT],
            [true, SettingType::BOOLEAN],
            ['x', SettingType::STRING],
            [['x'], SettingType::ARRAY],
        ];
    }

    /**
     * @dataProvider strictRefusals
     */
    public function test_the_strict_policy_refuses_every_other_type(mixed $value, string $type): void
    {
        $this->expectException(SettingTypeMismatchException::class);

        TypeNormalizer::normalize($value, $type, CastingPolicy::STRICT, 'key', 'scope');
    }

    /**
     * @return array<int, array{mixed, string}>
     */
    public static function strictRefusals(): array
    {
        return [
            ['1', SettingType::INTEGER],
            [1.0, SettingType::INTEGER],
            [1, SettingType::FLOAT],
            ['1.5', SettingType::FLOAT],
            ['true', SettingType::BOOLEAN],
            [1, SettingType::BOOLEAN],
            [1, SettingType::STRING],
            ['[]', SettingType::ARRAY],
        ];
    }

    /**
     * @dataProvider safeConversions
     */
    public function test_the_safe_policy_converts_the_unambiguous_cases(mixed $value, string $type, mixed $expected): void
    {
        $this->assertSame($expected, TypeNormalizer::normalize($value, $type, CastingPolicy::SAFE, 'key', 'scope'));
    }

    /**
     * @return array<string, array{mixed, string, mixed}>
     */
    public static function safeConversions(): array
    {
        return [
            'integer string' => ['20', SettingType::INTEGER, 20],
            'negative integer string' => ['-7', SettingType::INTEGER, -7],
            'zero string' => ['0', SettingType::INTEGER, 0],
            'largest integer' => [(string) PHP_INT_MAX, SettingType::INTEGER, PHP_INT_MAX],
            'integer to float' => [3, SettingType::FLOAT, 3.0],
            'decimal string' => ['19.99', SettingType::FLOAT, 19.99],
            'integer string to float' => ['20', SettingType::FLOAT, 20.0],
            'true string' => ['true', SettingType::BOOLEAN, true],
            'false string' => ['false', SettingType::BOOLEAN, false],
            'one string' => ['1', SettingType::BOOLEAN, true],
            'zero string to bool' => ['0', SettingType::BOOLEAN, false],
            'one' => [1, SettingType::BOOLEAN, true],
            'zero' => [0, SettingType::BOOLEAN, false],
            'integer to string' => [42, SettingType::STRING, '42'],
            'float to string' => [1.5, SettingType::STRING, '1.5'],
            'whole float to string' => [2.0, SettingType::STRING, '2.0'],
        ];
    }

    /**
     * @dataProvider safeRefusals
     */
    public function test_the_safe_policy_refuses_the_ambiguous_cases(mixed $value, string $type): void
    {
        $this->expectException(SettingTypeMismatchException::class);

        TypeNormalizer::normalize($value, $type, CastingPolicy::SAFE, 'key', 'scope');
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function safeRefusals(): array
    {
        return [
            'fraction' => ['20.5', SettingType::INTEGER],
            'overflow' => ['9223372036854775808', SettingType::INTEGER],
            'exponent' => ['1e3', SettingType::INTEGER],
            'padding' => ['020', SettingType::INTEGER],
            'spaces' => [' 20', SettingType::INTEGER],
            'plus sign' => ['+20', SettingType::INTEGER],
            'negative zero' => ['-0', SettingType::INTEGER],
            'float to integer' => [20.0, SettingType::INTEGER],
            'bool to integer' => [true, SettingType::INTEGER],
            'float exponent' => ['1e3', SettingType::FLOAT],
            'float precision' => ['1.2345678901234567', SettingType::FLOAT],
            'huge integer to float' => [PHP_INT_MAX, SettingType::FLOAT],
            'float text' => ['abc', SettingType::FLOAT],
            'yes' => ['yes', SettingType::BOOLEAN],
            'upper TRUE' => ['TRUE', SettingType::BOOLEAN],
            'two' => [2, SettingType::BOOLEAN],
            'empty string to bool' => ['', SettingType::BOOLEAN],
            'bool to string' => [true, SettingType::STRING],
            'array to string' => [['a'], SettingType::STRING],
            'string to array' => ['a', SettingType::ARRAY],
            'INF' => [INF, SettingType::FLOAT],
        ];
    }

    public function test_the_message_names_the_key_scope_and_types_but_never_the_value(): void
    {
        try {
            TypeNormalizer::normalize('s3cret', SettingType::INTEGER, CastingPolicy::STRICT, 'api.token', 'tenant');
            $this->fail('No exception was thrown.');
        } catch (SettingTypeMismatchException $exception) {
            $this->assertStringContainsString('[api.token]', $exception->getMessage());
            $this->assertStringContainsString('[tenant]', $exception->getMessage());
            $this->assertStringContainsString('[integer]', $exception->getMessage());
            $this->assertStringNotContainsString('s3cret', $exception->getMessage());
        }
    }
}
