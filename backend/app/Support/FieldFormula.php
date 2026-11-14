<?php

namespace App\Support;

/**
 * Formulas of computed type fields: numbers, field keys, + - * /, parentheses and unary minus.
 * Parsed by hand (recursive descent), never eval'd. "liters * price_per_liter" → 2356.
 *
 * Grammar: expr := term (('+'|'-') term)* ; term := factor (('*'|'/') factor)* ;
 *          factor := ('-'|'+') factor | number | key | '(' expr ')'
 */
final class FieldFormula
{
    public const MAX_LENGTH = 200;

    /**
     * Field keys the formula uses, in order of first use. Throws \InvalidArgumentException with a
     * user-facing message when the formula cannot be parsed.
     *
     * @return list<string>
     */
    public static function keys(string $formula): array
    {
        $tokens = self::tokens($formula);
        $pos = 0;
        $keys = [];
        self::expr($tokens, $pos, null, $keys);

        if ($pos !== count($tokens)) {
            throw new \InvalidArgumentException('Unexpected "' . $tokens[$pos][1] . '" in the formula!');
        }

        return array_values(array_unique($keys));
    }

    /**
     * The value for these inputs, or null when an input is missing / not a number, or on division
     * by zero. Booleans count as 1/0. Rounded to 2 decimals.
     *
     * @param array<string, mixed> $values
     */
    public static function evaluate(string $formula, array $values): ?float
    {
        try {
            $tokens = self::tokens($formula);
            $pos = 0;
            $keys = [];
            $result = self::expr($tokens, $pos, $values, $keys);
        } catch (\InvalidArgumentException|\DivisionByZeroError) {
            return null;
        }

        if ($pos !== count($tokens) || $result === null || !is_finite($result)) {
            return null;
        }

        return round($result, 2);
    }

    /**
     * @return list<array{0: string, 1: string}> [kind (num|key|op), text]
     */
    private static function tokens(string $formula): array
    {
        $formula = trim($formula);

        if ($formula === '') {
            throw new \InvalidArgumentException('The formula is empty!');
        }

        if (mb_strlen($formula) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException('The formula is too long!');
        }

        $tokens = [];
        $offset = 0;
        $length = strlen($formula);

        while ($offset < $length) {
            if (preg_match('/\G\s+/', $formula, $m, 0, $offset)) {
                $offset += strlen($m[0]);

                continue;
            }

            if (preg_match('/\G\d+(?:[.,]\d+)?/', $formula, $m, 0, $offset)) {
                $tokens[] = ['num', str_replace(',', '.', $m[0])];
            } elseif (preg_match('/\G[a-z_][a-z0-9_]*/i', $formula, $m, 0, $offset)) {
                $tokens[] = ['key', strtolower($m[0])];
            } elseif (preg_match('/\G[-+*\/()×÷]/u', $formula, $m, 0, $offset)) {
                $tokens[] = ['op', strtr($m[0], ['×' => '*', '÷' => '/'])];
            } else {
                throw new \InvalidArgumentException('Formulas can only use field keys, numbers, + - * / and parentheses!');
            }
            $offset += strlen($m[0]);
        }

        return $tokens;
    }

    /**
     * @param list<array{0: string, 1: string}> $t
     * @param null|array<string, mixed> $values null = only collect keys
     * @param list<string> $keys
     */
    private static function expr(array $t, int &$pos, ?array $values, array &$keys): ?float
    {
        $left = self::term($t, $pos, $values, $keys);

        while (isset($t[$pos]) && $t[$pos][0] === 'op' && in_array($t[$pos][1], ['+', '-'], true)) {
            $op = $t[$pos++][1];
            $right = self::term($t, $pos, $values, $keys);
            $left = ($left === null || $right === null) ? null : ($op === '+' ? $left + $right : $left - $right);
        }

        return $left;
    }

    private static function term(array $t, int &$pos, ?array $values, array &$keys): ?float
    {
        $left = self::factor($t, $pos, $values, $keys);

        while (isset($t[$pos]) && $t[$pos][0] === 'op' && in_array($t[$pos][1], ['*', '/'], true)) {
            $op = $t[$pos++][1];
            $right = self::factor($t, $pos, $values, $keys);

            if ($left === null || $right === null) {
                $left = null;
            } elseif ($op === '*') {
                $left *= $right;
            } else {
                if ($values !== null && abs($right) < 1e-12) {
                    throw new \DivisionByZeroError;
                }
                $left = $right == 0.0 ? null : $left / $right;
            }
        }

        return $left;
    }

    private static function factor(array $t, int &$pos, ?array $values, array &$keys, int $depth = 0): ?float
    {
        if ($depth > 32) {
            throw new \InvalidArgumentException('The formula is nested too deeply!');
        }

        $token = $t[$pos] ?? null;

        if ($token === null) {
            throw new \InvalidArgumentException('The formula ends too early!');
        }

        if ($token[0] === 'op' && in_array($token[1], ['-', '+'], true)) {
            $pos++;
            $value = self::factor($t, $pos, $values, $keys, $depth + 1);

            return $value === null ? null : ($token[1] === '-' ? -$value : $value);
        }

        if ($token[0] === 'num') {
            $pos++;

            return (float) $token[1];
        }

        if ($token[0] === 'key') {
            $pos++;
            $keys[] = $token[1];

            if ($values === null) {
                return 1.0;
            }
            $raw = $values[$token[1]] ?? null;

            return match (true) {
                is_bool($raw) => $raw ? 1.0 : 0.0,
                is_int($raw), is_float($raw) => (float) $raw,
                is_string($raw) && is_numeric($raw) => (float) $raw,
                default => null,
            };
        }

        if ($token[1] === '(') {
            $pos++;
            $value = self::expr($t, $pos, $values, $keys);

            if (($t[$pos][1] ?? null) !== ')') {
                throw new \InvalidArgumentException('A "(" in the formula is never closed!');
            }
            $pos++;

            return $value;
        }

        throw new \InvalidArgumentException('Unexpected "' . $token[1] . '" in the formula!');
    }
}
