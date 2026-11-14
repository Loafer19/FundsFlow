<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Lenient parsing of type field values written by people (or the agent) in Ukrainian or English:
 * "8 км", "8000 m" for a km field, "1 год 20 хв", "півтори години", "4/5", "8 з 10", "так".
 * Every method returns null when the text cannot be read; TransactionFields turns that into an error.
 */
final class FieldValueParser
{
    /**
     * canonical => [group, factor to the group's base unit, aliases]. Aliases are lowercase;
     * ambiguous ones ("m" metres/minutes, "г" grams/hours) resolve by the field's own unit.
     */
    private const UNITS = [
        'km' => ['length', 1000.0, ['km', 'kms', 'k', 'kilometer', 'kilometers', 'kilometre', 'kilometres', 'км', 'кілометр', 'кілометри', 'кілометрів', 'кілометра', 'кілометрах']],
        'm' => ['length', 1.0, ['m', 'meter', 'meters', 'metre', 'metres', 'м', 'метр', 'метри', 'метрів', 'метра']],
        'cm' => ['length', 0.01, ['cm', 'см', 'сантиметр', 'сантиметрів']],
        'mi' => ['length', 1609.344, ['mi', 'mile', 'miles', 'миля', 'милі', 'миль']],
        'kg' => ['mass', 1000.0, ['kg', 'kgs', 'kilo', 'kilos', 'kilogram', 'kilograms', 'кг', 'кіло', 'кілограм', 'кілограми', 'кілограмів']],
        'g' => ['mass', 1.0, ['g', 'gr', 'gram', 'grams', 'г', 'гр', 'грам', 'грами', 'грамів']],
        'lb' => ['mass', 453.592, ['lb', 'lbs', 'pound', 'pounds', 'фунт', 'фунти', 'фунтів']],
        'l' => ['volume', 1000.0, ['l', 'liter', 'liters', 'litre', 'litres', 'л', 'літр', 'літри', 'літрів', 'літра']],
        'ml' => ['volume', 1.0, ['ml', 'мл', 'мілілітр', 'мілілітрів']],
        'h' => ['time', 60.0, ['h', 'hr', 'hrs', 'hour', 'hours', 'год', 'година', 'години', 'годину', 'годин', 'г']],
        'min' => ['time', 1.0, ['min', 'mins', 'minute', 'minutes', 'm', 'хв', 'хвилина', 'хвилини', 'хвилину', 'хвилин']],
        's' => ['time', 1 / 60, ['s', 'sec', 'secs', 'second', 'seconds', 'с', 'сек', 'секунд', 'секунди', 'секунда']],
    ];

    private const WORD_NUMBERS = [
        'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10,
        'a' => 1, 'an' => 1, 'half' => 0.5,
        'один' => 1, 'одна' => 1, 'одну' => 1, 'два' => 2, 'дві' => 2, 'три' => 3, 'чотири' => 4, "п'ять" => 5, 'пʼять' => 5, 'шість' => 6, 'сім' => 7, 'вісім' => 8, "дев'ять" => 9, 'десять' => 10,
        'пів' => 0.5, 'півтори' => 1.5, 'півтора' => 1.5,
    ];

    private const TRUE_WORDS = ['1', 'true', 'yes', 'y', 'on', 'done', '+', '✓', '✅', 'так', 'ага', 'угу', 'да', 'було', 'зроблено', 'виконано'];

    private const FALSE_WORDS = ['0', 'false', 'no', 'n', 'off', '-', '❌', '✗', 'ні', 'не', 'нет', 'не було'];

    /**
     * Number for a field with an optional unit: "8,5 км" → 8.5; "8000 м" with unit km → 8;
     * "7 год 30 хв" with unit h → 7.5. Unknown words after the number are ignored ("3 склянки").
     * Null when there is no number or the unit is of another kind (kg into a km field).
     */
    public static function number(mixed $raw, ?string $fieldUnit = null): ?float
    {
        if (is_int($raw) || is_float($raw)) {
            return (float) $raw;
        }

        if (is_bool($raw) || $raw === null) {
            return null;
        }

        $text = self::normalize((string) $raw);

        if ($text === '') {
            return null;
        }

        $target = self::unit($fieldUnit);

        // Time-valued fields take any duration ("7 год 30 хв", "1:20").
        if ($target !== null && self::UNITS[$target][0] === 'time' && !is_numeric(str_replace(',', '.', $text))) {
            $minutes = self::minutes($text);

            return $minutes === null ? null : round($minutes / self::UNITS[$target][1], 4);
        }

        if (preg_match('/^[~≈]?\s*(-?\d{1,3}(?:[ \x{00A0}]\d{3})+|-?\d+(?:[.,]\d+)?)\s*(.*)$/u', $text, $m) !== 1) {
            return null;
        }

        $value = (float) str_replace([',', ' ', "\u{00A0}"], ['.', '', ''], $m[1]);
        $unitText = trim(preg_replace('/[.\s]+$/u', '', $m[2]) ?? '');

        if ($unitText === '' || $target === null) {
            return $value;
        }

        $from = self::unit($unitText, self::UNITS[$target][0]);

        if ($from === null) {
            return $value;
        }

        if (self::UNITS[$from][0] !== self::UNITS[$target][0]) {
            return null;
        }

        return round($value * self::UNITS[$from][1] / self::UNITS[$target][1], 4);
    }

    /**
     * "42", "42 хв", "1:20", "1h 20m", "1 год 20 хв", "1г20хв", "півтори години", "half an hour" → minutes.
     */
    public static function minutes(mixed $raw): ?int
    {
        if (is_int($raw) || is_float($raw)) {
            return $raw >= 0 ? (int) round($raw) : null;
        }

        if (!is_string($raw)) {
            return null;
        }

        $text = self::normalize($raw);

        if ($text === '') {
            return null;
        }

        if (is_numeric(str_replace(',', '.', $text))) {
            $n = (float) str_replace(',', '.', $text);

            return $n >= 0 ? (int) round($n) : null;
        }

        if (preg_match('/^(\d{1,3}):(\d{2})(?::(\d{2}))?$/', $text, $m)) {
            return (int) $m[1] * 60 + (int) $m[2] + (isset($m[3]) ? (int) round((int) $m[3] / 60) : 0);
        }

        if (preg_match('/^pt(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?$/', $text, $m) && $m[0] !== 'pt') {
            return (int) ($m[1] ?? 0) * 60 + (int) ($m[2] ?? 0) + (int) round((int) ($m[3] ?? 0) / 60);
        }

        // "півгодини" → "пів годин", "half an hour" → "0.5 hour", bare "годину" → "1 годину".
        $text = preg_replace('/\bhalf an? hour\b/u', '0.5 hour', $text) ?? $text;
        $text = preg_replace('/(^|\s)пів(?=год)/u', '$1пів ', $text) ?? $text;
        $text = preg_replace_callback('/(^|\s)(' . implode('|', array_map('preg_quote', array_keys(self::WORD_NUMBERS))) . ')(?=\s)/u', fn ($m) => $m[1] . self::WORD_NUMBERS[$m[2]], $text) ?? $text;

        $total = 0.0;
        $matched = false;
        $unit = '(hours?|hrs?|h|год\p{L}*|г|minutes?|mins?|m|хвилин\p{L}*|хв|seconds?|secs?|s|сек\p{L}*|с)';

        if (preg_match_all('/(\d+(?:[.,]\d+)?)\s*' . $unit . '(?!\p{L})/u', $text, $all, PREG_SET_ORDER)) {
            foreach ($all as $m) {
                $canonical = self::unit($m[2], 'time');

                if ($canonical === null) {
                    continue;
                }
                $total += (float) str_replace(',', '.', $m[1]) * self::UNITS[$canonical][1];
                $matched = true;
            }
        }

        // "годину", "an hour" with no number.
        if (!$matched && preg_match('/^(годину|година|hour|хвилину)$/u', $text, $m)) {
            return $m[1] === 'хвилину' ? 1 : 60;
        }

        return $matched ? (int) round($total) : null;
    }

    /**
     * "4", "4/5", "4 з 5", "8 out of 10" (rescaled to the field's max), "⭐⭐⭐⭐".
     */
    public static function rating(mixed $raw, int $min = 1, int $max = 5): ?int
    {
        if (is_int($raw) || is_float($raw)) {
            return (int) round($raw);
        }

        if (!is_string($raw)) {
            return null;
        }

        $text = self::normalize($raw);
        $stars = mb_substr_count($text, '⭐') + mb_substr_count($text, '★');

        if ($stars > 0 && preg_match('/\d/', $text) !== 1) {
            return $stars;
        }

        if (preg_match('/^(\d+(?:[.,]\d+)?)\s*(?:\/|з|із|out of|of|из)\s*(\d+)$/u', $text, $m)) {
            $value = (float) str_replace(',', '.', $m[1]);
            $scale = (int) $m[2];

            if ($scale <= 0 || $value > $scale) {
                return null;
            }

            return $scale === $max ? (int) round($value) : max($min, (int) round($value / $scale * $max));
        }

        $n = str_replace(',', '.', $text);

        return is_numeric($n) ? (int) round((float) $n) : null;
    }

    public static function boolean(mixed $raw): ?bool
    {
        if (is_bool($raw)) {
            return $raw;
        }

        if (is_int($raw) || is_float($raw)) {
            return $raw === 1 || $raw === 1.0 ? true : (($raw === 0 || $raw === 0.0) ? false : null);
        }

        $text = self::normalize((string) $raw);
        $text = rtrim($text, '!.');

        return match (true) {
            in_array($text, self::TRUE_WORDS, true) => true,
            in_array($text, self::FALSE_WORDS, true) => false,
            default => null,
        };
    }

    /**
     * Option by exact (case-insensitive), accent/space-insensitive, then a unique prefix or
     * substring match ("run" → "Run", "Йога" → "Йога", "swimming" → "Swim").
     *
     * @param list<string> $options
     */
    public static function option(array $options, mixed $raw): ?string
    {
        $value = self::normalize((string) (is_scalar($raw) ? $raw : ''));

        if ($value === '') {
            return null;
        }

        $fold = static fn (string $s): string => preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower(Str::ascii($s) !== '' ? Str::ascii($s) : $s)) ?? '';

        foreach ($options as $option) {
            if (mb_strtolower((string) $option) === $value) {
                return (string) $option;
            }
        }

        $needle = $fold($value);

        if ($needle === '') {
            return null;
        }

        foreach ($options as $option) {
            if ($fold((string) $option) === $needle) {
                return (string) $option;
            }
        }

        if (mb_strlen($needle) < 3) {
            return null;
        }

        $hits = array_values(array_filter($options, static function ($option) use ($fold, $needle): bool {
            $o = $fold((string) $option);

            return $o !== '' && mb_strlen($o) >= 3 && (str_starts_with($o, $needle) || str_starts_with($needle, $o));
        }));

        return count($hits) === 1 ? (string) $hits[0] : null;
    }

    /**
     * Canonical unit for free text ("км" → km), preferring a group when an alias is ambiguous.
     */
    public static function unit(?string $text, ?string $preferGroup = null): ?string
    {
        $text = mb_strtolower(trim((string) $text, " .\t\n"));

        if ($text === '') {
            return null;
        }

        $hits = [];

        foreach (self::UNITS as $canonical => [$group, , $aliases]) {
            if ($text === $canonical || in_array($text, $aliases, true)) {
                $hits[] = [$canonical, $group];
            }
        }

        foreach ($hits as [$canonical, $group]) {
            if ($group === $preferGroup) {
                return $canonical;
            }
        }

        return $hits[0][0] ?? null;
    }

    private static function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = str_replace(['’', '`'], "'", $text);

        return preg_replace('/\s+/u', ' ', $text) ?? $text;
    }
}
