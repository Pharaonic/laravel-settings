<?php

namespace Pharaonic\Laravel\Settings\Support;

use Illuminate\Database\QueryException;

/**
 * Recognizes a row refused by a unique index, on every supported driver and
 * Laravel line (UniqueConstraintViolationException only exists from 10.x).
 *
 * @internal
 */
final class UniqueViolation
{
    public static function matches(QueryException $exception): bool
    {
        $errorInfo = is_array($exception->errorInfo) ? $exception->errorInfo : [];
        $sqlState = isset($errorInfo[0]) && is_string($errorInfo[0]) ? $errorInfo[0] : (string) $exception->getCode();
        $driverCode = $errorInfo[1] ?? null;

        // PostgreSQL: unique_violation.
        if ($sqlState === '23505') {
            return true;
        }

        // MySQL and MariaDB: ER_DUP_ENTRY, ER_DUP_ENTRY_WITH_KEY_NAME.
        // SQL Server: duplicate key in a unique index or constraint.
        if (in_array($driverCode, [1062, 1586, 2601, 2627], true)) {
            return true;
        }

        // SQLite reports every constraint failure as code 19.
        return str_contains($exception->getMessage(), 'UNIQUE constraint failed');
    }
}
