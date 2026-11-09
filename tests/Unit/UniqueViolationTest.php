<?php

use Illuminate\Database\QueryException;
use Pharaonic\Laravel\Settings\Support\UniqueViolation;

/**
 * @param  array<int, mixed>  $errorInfo
 */
function unique_violation_exception(string $message, array $errorInfo): QueryException
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

dataset('unique_violation_uniqueViolations', function () {
    return [
        'pgsql' => ['duplicate key', ['23505', 7, 'duplicate key value violates unique constraint']],
        'mysql' => ['Duplicate entry', ['23000', 1062, 'Duplicate entry']],
        'sqlsrv' => ['Cannot insert duplicate key', ['23000', 2627, 'Violation of UNIQUE KEY constraint']],
        'sqlite' => ['UNIQUE constraint failed: settings.identifier, settings.key', ['23000', 19, 'UNIQUE constraint failed']],
    ];
});

dataset('unique_violation_otherErrors', function () {
    return [
        'not null' => ['NOT NULL constraint failed: settings.key', ['23000', 19, 'NOT NULL constraint failed']],
        'mysql foreign key' => ['Cannot add or update a child row', ['23000', 1452, 'foreign key']],
        'pgsql not null' => ['null value', ['23502', 7, 'null value in column']],
    ];
});

test('unique violations are recognized on every driver', function (string $message, array $errorInfo) {
    $this->assertTrue(UniqueViolation::matches(unique_violation_exception($message, $errorInfo)));
})->with('unique_violation_uniqueViolations');

test('other integrity errors are not unique violations', function (string $message, array $errorInfo) {
    $this->assertFalse(UniqueViolation::matches(unique_violation_exception($message, $errorInfo)));
})->with('unique_violation_otherErrors');
