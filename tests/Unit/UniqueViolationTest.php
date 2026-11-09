<?php

namespace Pharaonic\Laravel\Settings\Tests\Unit;

use Illuminate\Database\QueryException;
use PDOException;
use Pharaonic\Laravel\Settings\Support\UniqueViolation;
use Pharaonic\Laravel\Settings\Tests\TestCase;
use ReflectionMethod;

final class UniqueViolationTest extends TestCase
{
    /**
     * @dataProvider uniqueViolations
     *
     * @param  array<int, mixed>  $errorInfo
     */
    public function test_unique_violations_are_recognized_on_every_driver(string $message, array $errorInfo): void
    {
        $this->assertTrue(UniqueViolation::matches(self::exception($message, $errorInfo)));
    }

    /**
     * @return array<string, array{string, array<int, mixed>}>
     */
    public static function uniqueViolations(): array
    {
        return [
            'pgsql' => ['duplicate key', ['23505', 7, 'duplicate key value violates unique constraint']],
            'mysql' => ['Duplicate entry', ['23000', 1062, 'Duplicate entry']],
            'sqlsrv' => ['Cannot insert duplicate key', ['23000', 2627, 'Violation of UNIQUE KEY constraint']],
            'sqlite' => ['UNIQUE constraint failed: settings.identifier, settings.key', ['23000', 19, 'UNIQUE constraint failed']],
        ];
    }

    /**
     * @dataProvider otherErrors
     *
     * @param  array<int, mixed>  $errorInfo
     */
    public function test_other_integrity_errors_are_not_unique_violations(string $message, array $errorInfo): void
    {
        $this->assertFalse(UniqueViolation::matches(self::exception($message, $errorInfo)));
    }

    /**
     * @return array<string, array{string, array<int, mixed>}>
     */
    public static function otherErrors(): array
    {
        return [
            'not null' => ['NOT NULL constraint failed: settings.key', ['23000', 19, 'NOT NULL constraint failed']],
            'mysql foreign key' => ['Cannot add or update a child row', ['23000', 1452, 'foreign key']],
            'pgsql not null' => ['null value', ['23502', 7, 'null value in column']],
        ];
    }

    /**
     * @param  array<int, mixed>  $errorInfo
     */
    private static function exception(string $message, array $errorInfo): QueryException
    {
        $previous = new PDOException($message);
        $previous->errorInfo = $errorInfo;

        // Laravel 10 added the connection name as the first argument.
        $arguments = (new ReflectionMethod(QueryException::class, '__construct'))->getNumberOfParameters() >= 4
            ? ['testing', 'insert', [], $previous]
            : ['insert', [], $previous];

        $exception = new QueryException(...$arguments);
        $exception->errorInfo = $errorInfo;

        return $exception;
    }
}
