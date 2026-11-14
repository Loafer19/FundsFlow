<?php

namespace App\Support;

use App\Models\TransactionType;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Transaction type field schema and value validation (ported from TaleLog's EventFields, plus
 * computed fields).
 *
 * A field: {key, label, type, required, hidden, unit?, min?, max?, options?, formula?, fill_amount?}.
 * - `key` is stable: renaming the label keeps it, so stored values survive.
 * - A field removed in the editor is kept with hidden=true: its values stay in transactions.data,
 *   they are just not shown or asked for.
 * - Values live in transactions.data by key: number → float, rating → int, duration → minutes,
 *   boolean → bool, select → string, multiselect → list, text → string, date → Y-m-d,
 *   computed → float (evaluated on save from other numeric fields; input for it is ignored).
 * - A computed field with fill_amount writes its result to the transaction amount (see
 *   TransactionTypeData); at most one per type.
 */
final class TransactionFields
{
    public const TYPES = ['number', 'rating', 'duration', 'boolean', 'select', 'multiselect', 'text', 'date', 'computed'];

    /** Fields a formula (and charts later) can use. */
    public const NUMERIC = ['number', 'rating', 'duration', 'boolean'];

    public const RATING_SCALES = [3, 5, 10];

    public const MAX_FIELDS = 30;

    public const MAX_TEXT = 500;

    /**
     * Clean an editor payload into a schema, keeping keys of existing fields and appending
     * removed fields as hidden.
     *
     * @param list<array<string, mixed>> $fields
     * @param list<array<string, mixed>> $previous
     * @return list<array<string, mixed>>
     */
    public static function normalizeSchema(array $fields, array $previous = []): array
    {
        $previousByKey = [];

        foreach ($previous as $field) {
            if (isset($field['key'])) {
                $previousByKey[(string) $field['key']] = $field;
            }
        }

        $errors = [];
        $out = [];
        $used = [];

        foreach (array_values($fields) as $i => $field) {
            if (!is_array($field)) {
                continue;
            }

            $label = trim((string) ($field['label'] ?? ''));
            $type = (string) ($field['type'] ?? '');

            if ($label === '') {
                $errors["fields.{$i}.label"] = 'Every field needs a label!';

                continue;
            }

            if (!in_array($type, self::TYPES, true)) {
                $errors["fields.{$i}.type"] = "Unknown field type \"{$type}\"!";

                continue;
            }

            $key = (string) ($field['key'] ?? '');

            if ($key === '' || !isset($previousByKey[$key])) {
                $key = self::uniqueKey($key !== '' ? $key : $label, [...array_keys($used), ...array_keys($previousByKey)]);
            }

            if (isset($used[$key])) {
                $key = self::uniqueKey($key, [...array_keys($used), ...array_keys($previousByKey)]);
            }

            $clean = [
                'key' => $key,
                'label' => mb_substr($label, 0, 64),
                'type' => $type,
                'required' => $type !== 'computed' && (bool) ($field['required'] ?? false),
                'hidden' => (bool) ($field['hidden'] ?? false),
            ];

            if (in_array($type, ['number', 'computed'], true)) {
                $clean['unit'] = mb_substr(trim((string) ($field['unit'] ?? '')), 0, 16) ?: null;
            }

            if ($type === 'number') {
                $clean['min'] = is_numeric($field['min'] ?? null) ? (float) $field['min'] : null;
                $clean['max'] = is_numeric($field['max'] ?? null) ? (float) $field['max'] : null;

                if ($clean['min'] !== null && $clean['max'] !== null && $clean['min'] > $clean['max']) {
                    $errors["fields.{$i}.min"] = "\"{$label}\": min is bigger than max!";
                }
            }

            if ($type === 'rating') {
                $max = (int) ($field['max'] ?? 5);
                $clean['min'] = 1;
                $clean['max'] = in_array($max, self::RATING_SCALES, true) ? $max : 5;
            }

            if (in_array($type, ['select', 'multiselect'], true)) {
                $options = array_values(array_unique(array_filter(array_map(
                    static fn ($option): string => mb_substr(trim((string) $option), 0, SelectOptions::MAX_LENGTH),
                    is_array($field['options'] ?? null) ? $field['options'] : [],
                ), static fn (string $option): bool => $option !== '')));

                if ($options === []) {
                    $errors["fields.{$i}.options"] = "\"{$label}\" needs at least one option!";
                }

                $clean['options'] = array_slice($options, 0, SelectOptions::MAX_OPTIONS);
            }

            if ($type === 'computed') {
                $clean['formula'] = trim((string) ($field['formula'] ?? ''));
                $clean['fill_amount'] = (bool) ($field['fill_amount'] ?? false);
                $clean['_i'] = $i;
            }

            $used[$key] = true;
            $out[] = $clean;
        }

        // Fields dropped in the editor stay, hidden, so their values are not lost.
        foreach ($previousByKey as $key => $field) {
            if (!isset($used[$key])) {
                $out[] = [...$field, 'hidden' => true];
            }
        }

        // Formulas may only use number / rating / duration / yes-no fields of this type.
        $numericKeys = [];

        foreach ($out as $field) {
            if (in_array($field['type'], self::NUMERIC, true)) {
                $numericKeys[$field['key']] = true;
            }
        }
        $fills = 0;

        foreach ($out as $n => $field) {
            if ($field['type'] !== 'computed') {
                continue;
            }
            $i = $field['_i'] ?? null;
            unset($out[$n]['_i']);

            if ($i === null) {
                continue; // a hidden previous field: already valid when it was saved
            }

            try {
                $keys = FieldFormula::keys((string) $field['formula']);
                $unknown = array_values(array_filter($keys, static fn (string $key): bool => !isset($numericKeys[$key])));

                if ($unknown !== []) {
                    $errors["fields.{$i}.formula"] = "\"{$field['label']}\": the formula uses " . implode(', ', $unknown) . ', which is not a number field of this type!';
                }
            } catch (\InvalidArgumentException $e) {
                $errors["fields.{$i}.formula"] = "\"{$field['label']}\": " . $e->getMessage();
            }

            if ($field['fill_amount'] && !$field['hidden'] && ++$fills > 1) {
                $errors["fields.{$i}.fill_amount"] = 'Only one computed field can fill the amount!';
            }
        }
        $out = array_values($out);

        if (count($out) > self::MAX_FIELDS) {
            $errors['fields'] = 'A type can have at most ' . self::MAX_FIELDS . ' fields!';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $out;
    }

    /**
     * Validate and coerce values for a type, then evaluate its computed fields. Values of hidden or
     * unknown fields from $previous are kept as they were; incoming values for them are ignored.
     * $requireAll=false lets the assistant save with a required field still missing.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $previous
     * @return array<string, mixed>
     */
    public static function validate(TransactionType $type, array $input, array $previous = [], string $prefix = 'data', bool $requireAll = true): array
    {
        $errors = [];
        $out = [];
        $visibleKeys = [];

        foreach ($type->visibleFields() as $field) {
            $key = (string) $field['key'];
            $visibleKeys[$key] = true;

            if ($field['type'] === 'computed') {
                continue;
            }
            $has = array_key_exists($key, $input);
            $raw = $has ? $input[$key] : ($previous[$key] ?? null);

            if (self::isEmpty($raw)) {
                if ($requireAll && ($field['required'] ?? false)) {
                    $errors["{$prefix}.{$key}"] = $field['label'] . ' is required!';
                }

                continue;
            }

            $value = self::coerce($field, $raw, $error);

            if ($error !== null) {
                $errors["{$prefix}.{$key}"] = $error;

                continue;
            }

            $out[$key] = $value;
        }

        foreach ($type->visibleFields() as $field) {
            if ($field['type'] === 'computed') {
                $value = FieldFormula::evaluate((string) ($field['formula'] ?? ''), $out);

                if ($value !== null) {
                    $out[(string) $field['key']] = $value;
                }
            }
        }

        foreach ($previous as $key => $value) {
            if (!isset($visibleKeys[$key])) {
                $out[$key] = $value;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $out;
    }

    /**
     * Only the values that pass validation (the rest dropped), required fields not enforced.
     *
     * @param array<string, mixed> $input
     * @return array{0: array<string, mixed>, 1: list<string>} [values, dropped keys]
     */
    public static function validSubset(TransactionType $type, array $input): array
    {
        $out = [];
        $dropped = [];

        foreach ($input as $key => $value) {
            try {
                $one = self::validate($type, [$key => $value], requireAll: false);
                $out += array_intersect_key($one, [$key => true]);
            } catch (ValidationException) {
                $dropped[] = (string) $key;
            }
        }

        return [$out, $dropped];
    }

    /**
     * Required visible fields that have no value yet.
     *
     * @param array<string, mixed> $data
     * @return list<array<string, mixed>>
     */
    public static function missingRequired(TransactionType $type, array $data): array
    {
        return array_values(array_filter(
            $type->visibleFields(),
            static fn (array $field): bool => ($field['required'] ?? false) && self::isEmpty($data[$field['key']] ?? null),
        ));
    }

    /**
     * "40 l", "58,90 ₴", "4/5", "Yes" for cards, widgets and the assistant.
     *
     * @param array<string, mixed> $field
     */
    public static function display(array $field, mixed $value, ?User $user = null): string
    {
        return match ($field['type']) {
            'number', 'computed' => self::formatNumber((float) $value, $field['unit'] ?? null, $user),
            'rating' => (int) $value . '/' . (int) ($field['max'] ?? 5),
            'duration' => self::formatMinutes((int) $value),
            'boolean' => $value ? 'Yes' : 'No',
            'multiselect' => implode(', ', (array) $value),
            'date' => Carbon::parse((string) $value)->format('d.m.Y'),
            default => (string) $value,
        };
    }

    /**
     * Visible fields that have a value: [[label, shown value], …] in schema order.
     *
     * @param null|array<string, mixed> $data
     * @return list<array{0: string, 1: string, 2: string}> [label, value, key]
     */
    public static function rows(TransactionType $type, ?array $data, ?User $user = null): array
    {
        $rows = [];

        foreach ($type->visibleFields() as $field) {
            $value = $data[$field['key']] ?? null;

            if (self::isEmpty($value)) {
                continue;
            }
            $rows[] = [(string) $field['label'], self::display($field, $value, $user), (string) $field['key']];
        }

        return $rows;
    }

    /** "40 l · 58,90 ₴ · OKKO": the first few values for a list row or card line. */
    public static function summary(TransactionType $type, ?array $data, ?User $user = null, int $limit = 3): string
    {
        $values = array_map(static fn (array $row): string => $row[1], array_slice(
            array_values(array_filter(self::rows($type, $data, $user), static fn (array $row): bool => !self::isComputedFill($type, $row[2]))),
            0,
            $limit,
        ));

        return implode(' · ', $values);
    }

    public static function formatNumber(float $number, ?string $unit = null, ?User $user = null): string
    {
        $money = $unit !== null && self::isCurrencyUnit($unit);
        $locale = $user?->moneyFormat() ?? 'uk-UA';
        $text = null;

        if (class_exists(\NumberFormatter::class)) {
            $formatter = new \NumberFormatter($locale, \NumberFormatter::DECIMAL);
            $formatter->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, $money ? 2 : 0);
            $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, $money ? 2 : 4);
            $text = $formatter->format($number);
            $text = is_string($text) ? str_replace(["\u{202F}", "\u{00A0}"], ' ', $text) : null;
        }

        $text ??= $money ? number_format($number, 2, ',', ' ') : rtrim(rtrim(number_format($number, 4, ',', ' '), '0'), ',');

        return $unit ? "{$text} {$unit}" : $text;
    }

    public static function isCurrencyUnit(string $unit): bool
    {
        return in_array(mb_strtolower(trim($unit)), ['₴', 'грн', 'uah', '$', 'usd', '€', 'eur', '£', 'zł', 'pln'], true);
    }

    public static function formatMinutes(int $minutes): string
    {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return $h > 0 ? ($m > 0 ? "{$h}h {$m}m" : "{$h}h") : "{$m}m";
    }

    /**
     * Compact schema line for the assistant prompt: "liters*:number(l) · total=liters*price_per_liter→amount".
     */
    public static function promptSchema(TransactionType $type): string
    {
        $parts = [];

        foreach ($type->visibleFields() as $field) {
            $key = (string) $field['key'];
            $name = $key . (($field['required'] ?? false) ? '*' : '');
            $label = Str::slug(Str::ascii((string) $field['label']), '_') === $key ? '' : ' "' . $field['label'] . '"';
            $parts[] = match ($field['type']) {
                'number' => "{$name}:number" . (($field['unit'] ?? null) ? "({$field['unit']})" : '') . $label,
                'rating' => "{$name}:rating(1-" . (int) ($field['max'] ?? 5) . ')' . $label,
                'duration' => "{$name}:minutes{$label}",
                'select' => "{$name}:one of(" . implode('|', array_slice($field['options'] ?? [], 0, 12)) . ')' . $label,
                'multiselect' => "{$name}:several of(" . implode('|', array_slice($field['options'] ?? [], 0, 12)) . ')' . $label,
                'computed' => "{$key}={$field['formula']}" . (($field['fill_amount'] ?? false) ? '→amount' : '') . ' (auto)',
                default => "{$name}:{$field['type']}{$label}",
            };
        }

        return implode(' · ', $parts);
    }

    private static function isComputedFill(TransactionType $type, string $key): bool
    {
        return ($type->fillAmountField()['key'] ?? null) === $key;
    }

    /**
     * @param array<string, mixed> $field
     */
    private static function coerce(array $field, mixed $raw, ?string &$error): mixed
    {
        $error = null;
        $label = (string) $field['label'];

        switch ($field['type']) {
            case 'number':
            case 'rating':
                $number = $field['type'] === 'rating'
                    ? FieldValueParser::rating($raw, (int) ($field['min'] ?? 1), (int) ($field['max'] ?? 5))
                    : FieldValueParser::number(self::stripCurrency($raw, $field['unit'] ?? null), $field['unit'] ?? null);

                if ($number === null) {
                    $error = ($field['unit'] ?? null)
                        ? "{$label} must be a number in {$field['unit']}!"
                        : "{$label} must be a number!";

                    return null;
                }
                $number = (float) $number;

                if (isset($field['min']) && $field['min'] !== null && $number < (float) $field['min']) {
                    $error = "{$label} must be at least " . self::trimNumber((float) $field['min']) . '!';

                    return null;
                }

                if (isset($field['max']) && $field['max'] !== null && $number > (float) $field['max']) {
                    $error = "{$label} must be at most " . self::trimNumber((float) $field['max']) . '!';

                    return null;
                }

                return $field['type'] === 'rating' ? (int) $number : $number;

            case 'duration':
                $minutes = FieldValueParser::minutes($raw);

                if ($minutes === null) {
                    $error = "{$label} must be a duration like 45m or 1h 20m!";
                }

                return $minutes;

            case 'boolean':
                $bool = FieldValueParser::boolean($raw);

                if ($bool === null) {
                    $error = "{$label} must be yes or no!";
                }

                return $bool;

            case 'select':
                $match = SelectOptions::match($field['options'] ?? [], is_scalar($raw) ? (string) $raw : '');

                if ($match === null) {
                    $error = "{$label} must be one of: " . implode(', ', $field['options'] ?? []) . '!';
                }

                return $match;

            case 'multiselect':
                $out = [];

                foreach (SelectOptions::split($raw) as $value) {
                    $match = SelectOptions::match($field['options'] ?? [], $value);

                    if ($match === null) {
                        $error = "{$label} must be any of: " . implode(', ', $field['options'] ?? []) . '!';

                        return null;
                    }
                    $out[$match] = true;
                }

                return array_keys($out);

            case 'text':
                $text = trim(is_scalar($raw) ? (string) $raw : '');

                if (mb_strlen($text) > self::MAX_TEXT) {
                    $error = "{$label} is too long!";
                }

                return $text;

            case 'date':
                try {
                    return Carbon::parse((string) $raw)->toDateString();
                } catch (\Throwable) {
                    $error = "{$label} must be a date!";

                    return null;
                }
        }

        return $raw;
    }

    /** "58,90 ₴" / "58.9 грн" for a ₴ field: the parser does not know currencies, so drop the unit. */
    private static function stripCurrency(mixed $raw, ?string $unit): mixed
    {
        if (!is_string($raw)) {
            return $raw;
        }

        $clean = trim(preg_replace('/(₴|грн\.?|uah|\$|usd|€|eur)/iu', '', $raw) ?? $raw);

        return $clean === '' ? $raw : $clean;
    }

    private static function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    private static function trimNumber(float $number): string
    {
        return rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
    }

    /**
     * @param list<string> $taken
     */
    private static function uniqueKey(string $base, array $taken): string
    {
        $slug = Str::slug(Str::ascii($base), '_') ?: 'field';
        $slug = mb_substr($slug, 0, 32);

        if (preg_match('/^\d/', $slug)) {
            $slug = 'f_' . $slug; // keys are formula identifiers too
        }
        $key = $slug;
        $n = 2;

        while (in_array($key, $taken, true)) {
            $key = $slug . '_' . $n++;
        }

        return $key;
    }
}
