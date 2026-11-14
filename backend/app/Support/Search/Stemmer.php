<?php

namespace App\Support\Search;

/**
 * Words → index keys for search_stems and the vocabulary: Cyrillic words go through the
 * Ukrainian stemmer, Latin ones through Porter, numbers stay as they are.
 */
final class Stemmer
{
    /** Longest key kept (the vocabulary column is 64 chars). */
    public const MAX_LENGTH = 40;

    /**
     * Lowercased words of a text ("м'ясо" keeps its apostrophe out: мясо).
     *
     * @return list<string>
     */
    public static function words(string $text): array
    {
        preg_match_all("/[\\p{L}\\p{N}]+(?:['’ʼ`][\\p{L}]+)*/u", mb_strtolower($text), $m);

        return array_map(static fn (string $w) => str_replace(["'", '’', 'ʼ', '`'], '', $w), $m[0]);
    }

    public static function stem(string $word): string
    {
        $word = mb_strtolower($word);

        $stem = match (true) {
            (bool) preg_match('/^[a-z]+$/', $word) => PorterStemmer::stem($word),
            (bool) preg_match('/^\p{Cyrillic}+$/u', $word) => UkrainianStemmer::stem($word),
            default => $word,
        };

        return mb_substr($stem, 0, self::MAX_LENGTH);
    }

    /**
     * Unique keys of a text, single letters dropped.
     *
     * @return list<string>
     */
    public static function stems(string $text): array
    {
        $out = [];

        foreach (self::words($text) as $word) {
            if (mb_strlen($word) < 2) {
                continue;
            }
            $out[self::stem($word)] = true;
        }

        return array_map('strval', array_keys($out));
    }

    /** " key key key " — padded so LIKE '% key%' finds a key at a word start. */
    public static function column(array $stems): string
    {
        return $stems === [] ? '' : ' ' . implode(' ', $stems) . ' ';
    }

    /** Levenshtein distance by characters (PHP's levenshtein() counts bytes). */
    public static function distance(string $a, string $b, int $max = PHP_INT_MAX): int
    {
        $x = mb_str_split($a);
        $y = mb_str_split($b);
        $n = count($x);
        $m = count($y);

        if (abs($n - $m) > $max) {
            return $max + 1;
        }

        $prev = range(0, $m);

        for ($i = 1; $i <= $n; $i++) {
            $cur = [$i];
            $best = $i;

            for ($j = 1; $j <= $m; $j++) {
                $cur[$j] = min($prev[$j] + 1, $cur[$j - 1] + 1, $prev[$j - 1] + ($x[$i - 1] === $y[$j - 1] ? 0 : 1));
                $best = min($best, $cur[$j]);
            }

            if ($best > $max) {
                return $max + 1;
            }
            $prev = $cur;
        }

        return $prev[$m];
    }

    /** Typos allowed for a key of this length: none up to 3 letters, 1 up to 6, then 2. */
    public static function allowedTypos(string $stem): int
    {
        $len = mb_strlen($stem);

        return $len <= 3 ? 0 : ($len <= 6 ? 1 : 2);
    }
}
