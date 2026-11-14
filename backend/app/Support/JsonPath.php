<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Driver-specific SQL for reading a JSON object key and a year-month bucket.
 * Production uses MySQL; local tests use SQLite. Postgres is included if the
 * connection is ever switched.
 */
final class JsonPath
{
    public static function keyIsSafe(string $key): bool
    {
        return preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key) === 1;
    }

    /**
     * Text of `column->key` (NULL when the key is missing or JSON null).
     */
    public static function text(string $column, string $key): string
    {
        $key = self::assertKey($key);
        $col = self::wrap($column);
        $driver = DB::connection()->getDriverName();
        $path = DB::getPdo()->quote('$.' . $key);

        $extracted = match ($driver) {
            'mysql', 'mariadb' => "JSON_UNQUOTE(JSON_EXTRACT({$col}, {$path}))",
            'pgsql' => "({$col}->>" . DB::getPdo()->quote($key) . ')',
            default => "json_extract({$col}, {$path})",
        };

        // json_extract on SQLite returns the literal `null` for JSON null; MySQL already yields SQL NULL.
        return "NULLIF({$extracted}, 'null')";
    }

    /**
     * Numeric value of `column->key`, or SQL NULL when missing / not a number.
     */
    public static function number(string $column, string $key): string
    {
        $text = self::text($column, $key);

        return "CAST(CASE WHEN {$text} IS NULL OR TRIM({$text}) IN ('', 'true', 'false') THEN NULL ELSE {$text} END AS DECIMAL(16, 4))";
    }

    /** `YYYY-MM` from a datetime column. */
    public static function yearMonth(string $column = 'at'): string
    {
        $col = self::wrap($column);

        return match (DB::connection()->getDriverName()) {
            'mysql', 'mariadb' => "DATE_FORMAT({$col}, '%Y-%m')",
            'pgsql' => "to_char({$col}, 'YYYY-MM')",
            default => "strftime('%Y-%m', {$col})",
        };
    }

    private static function assertKey(string $key): string
    {
        if (!self::keyIsSafe($key)) {
            throw new InvalidArgumentException('Invalid JSON field key.');
        }

        return $key;
    }

    private static function wrap(string $column): string
    {
        $parts = explode('.', $column);
        $grammar = DB::connection()->getQueryGrammar();

        return implode('.', array_map(fn (string $part): string => $grammar->wrap($part), $parts));
    }
}
