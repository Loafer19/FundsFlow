<?php

namespace App\Support;

use App\Support\Search\Stemmer;

/**
 * The one place select / multiselect values are matched to a field's options, and where a new
 * option is judged acceptable.
 *
 * match(): FieldValueParser::option (exact, case/punctuation-insensitive, unique prefix), then a
 * same-alphabet fuzzy pass: equal stems (search v2 stemmer: "бігом" → "Біг") or a small
 * Levenshtein distance ("Hdes" → "Hades", "styxx" → "Styx"). Latin never fuzzy-matches Cyrillic,
 * so a transliteration is not silently merged into another option.
 *
 * newOption(): a value that matches nothing may become a new option ("граю в Hades" → Game gets
 * Hades) only when it is in the same alphabet as the existing options; a Cyrillic "біг" for an
 * English Run/Gym list is a translation the card should ask about, not a new option.
 */
// Ported from TaleLog (App\Support\SelectOptions); the stemmer in App\Support\Search came along with it.
final class SelectOptions
{
    public const MAX_LENGTH = 48;

    public const MAX_OPTIONS = 60;

    /**
     * @param array<int, string>|list<string> $options
     */
    public static function match(array $options, mixed $value): ?string
    {
        $options = array_values(array_map('strval', $options));
        $hit = FieldValueParser::option($options, $value);
        $text = is_scalar($value) ? (string) $value : '';

        // A prefix hit with more words is another thing: "Hades II" is not Hades, "Doom Eternal" not Doom.
        if ($hit !== null && self::fold($hit) !== self::fold($text) && count(Stemmer::words($text)) > count(Stemmer::words($hit))) {
            $hit = null;
        }

        return $hit ?? self::fuzzy($options, $text);
    }

    /**
     * @param list<string> $options
     */
    public static function fuzzy(array $options, string $value): ?string
    {
        $needle = self::fold($value);

        if (mb_strlen($needle) < 3) {
            return null;
        }

        $script = self::script($needle);
        $stems = self::stems($value);
        $best = null;
        $bestDistance = PHP_INT_MAX;
        $tie = false;

        foreach ($options as $option) {
            $folded = self::fold($option);

            if ($folded === '' || self::script($folded) !== $script) {
                continue;
            }

            if ($stems !== '' && self::stems($option) === $stems) {
                $distance = 0;
            } else {
                // Typos allowed by the shorter word, like search v2 (none up to 3 letters), and only
                // with the same first letter: "Stix" → Styx, but "Doom" is not "Room", "Food" not "Mood".
                $allowed = mb_substr($folded, 0, 1) === mb_substr($needle, 0, 1)
                    ? Stemmer::allowedTypos(mb_strlen($folded) < mb_strlen($needle) ? $folded : $needle)
                    : 0;
                $distance = $allowed === 0 ? PHP_INT_MAX : Stemmer::distance($needle, $folded, $allowed);

                if ($distance > $allowed) {
                    continue;
                }
            }

            if ($distance < $bestDistance) {
                [$best, $bestDistance, $tie] = [$option, $distance, false];
            } elseif ($distance === $bestDistance) {
                $tie = true;
            }
        }

        return $tie ? null : $best;
    }

    /**
     * The value as a new option ("hades" → "Hades"), or null when it should not become one:
     * empty, too long, a sentence, or in another alphabet than the existing options.
     *
     * @param list<string> $options
     */
    public static function newOption(array $options, mixed $value): ?string
    {
        if (!is_scalar($value) || is_bool($value)) {
            return null;
        }

        $clean = trim(preg_replace('/\s+/u', ' ', trim((string) $value, " \t\n\r\0\x0B\"'«»“”.,;:!?")) ?? '');

        if ($clean === '' || mb_strlen($clean) > self::MAX_LENGTH || !preg_match('/[\p{L}\p{N}]/u', $clean) || count(explode(' ', $clean)) > 5) {
            return null;
        }

        if (count($options) >= self::MAX_OPTIONS) {
            return null;
        }

        $script = self::script(self::fold($clean));
        $scripts = array_unique(array_filter(array_map(fn ($o) => self::script(self::fold((string) $o)), $options), fn ($s) => $s !== 'other'));

        if ($script !== 'other' && $scripts !== [] && !in_array($script, $scripts, true)) {
            return null;
        }

        // All lower case ("hades") → capitalised like a name; anything else is kept as written.
        if ($clean === mb_strtolower($clean)) {
            $clean = mb_strtoupper(mb_substr($clean, 0, 1)) . mb_substr($clean, 1);
        }

        return $clean;
    }

    /**
     * Multiselect input: a list, or "Styx, Hades і Celeste".
     *
     * @return list<string>
     */
    public static function split(mixed $raw): array
    {
        $values = is_array($raw) ? $raw : preg_split('/\s*(?:[,;]|\s(?:і|й|та|and|&)\s)\s*/u', (string) $raw);

        return array_values(array_filter(array_map(fn ($v) => is_scalar($v) ? trim((string) $v) : '', (array) $values), fn ($v) => $v !== ''));
    }

    private static function fold(string $value): string
    {
        return preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($value)) ?? '';
    }

    private static function stems(string $value): string
    {
        return implode(' ', array_map(fn (string $w) => Stemmer::stem($w), Stemmer::words($value)));
    }

    private static function script(string $folded): string
    {
        return match (true) {
            (bool) preg_match('/\p{Cyrillic}/u', $folded) => 'cyrillic',
            (bool) preg_match('/[a-z]/', $folded) => 'latin',
            default => 'other',
        };
    }
}
