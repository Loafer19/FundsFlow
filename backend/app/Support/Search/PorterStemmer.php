<?php

namespace App\Support\Search;

/**
 * The classic Porter (1980) stemmer for English words: running/runs/runner → run, cities → citi.
 * Input is one lowercase a-z word; anything else is returned unchanged.
 */
final class PorterStemmer
{
    private const STEP2 = [
        'ational' => 'ate', 'tional' => 'tion', 'enci' => 'ence', 'anci' => 'ance', 'izer' => 'ize', 'bli' => 'ble',
        'alli' => 'al', 'entli' => 'ent', 'eli' => 'e', 'ousli' => 'ous', 'ization' => 'ize', 'ation' => 'ate',
        'ator' => 'ate', 'alism' => 'al', 'iveness' => 'ive', 'fulness' => 'ful', 'ousness' => 'ous', 'aliti' => 'al',
        'iviti' => 'ive', 'biliti' => 'ble', 'logi' => 'log',
    ];

    private const STEP3 = [
        'icate' => 'ic', 'ative' => '', 'alize' => 'al', 'iciti' => 'ic', 'ical' => 'ic', 'ful' => '', 'ness' => '',
    ];

    private const STEP4 = [
        'al', 'ance', 'ence', 'er', 'ic', 'able', 'ible', 'ant', 'ement', 'ment', 'ent', 'ion', 'ou', 'ism', 'ate',
        'iti', 'ous', 'ive', 'ize',
    ];

    public static function stem(string $word): string
    {
        if (strlen($word) <= 2 || !preg_match('/^[a-z]+$/', $word)) {
            return $word;
        }

        $w = self::step1a($word);
        $w = self::step1b($w);
        $w = self::step1c($w);
        $w = self::replace($w, self::STEP2, 0);
        $w = self::replace($w, self::STEP3, 0);
        $w = self::step4($w);

        return self::step5($w);
    }

    private static function step1a(string $w): string
    {
        return match (true) {
            str_ends_with($w, 'sses') => substr($w, 0, -2),
            str_ends_with($w, 'ies') => substr($w, 0, -2),
            str_ends_with($w, 'ss') => $w,
            str_ends_with($w, 's') => substr($w, 0, -1),
            default => $w,
        };
    }

    private static function step1b(string $w): string
    {
        if (str_ends_with($w, 'eed')) {
            return self::measure(substr($w, 0, -3)) > 0 ? substr($w, 0, -1) : $w;
        }

        foreach (['ed', 'ing'] as $suffix) {
            if (str_ends_with($w, $suffix) && self::hasVowel($stem = substr($w, 0, -strlen($suffix)))) {
                if (str_ends_with($stem, 'at') || str_ends_with($stem, 'bl') || str_ends_with($stem, 'iz')) {
                    return $stem . 'e';
                }

                if (self::doubleConsonant($stem) && !in_array(substr($stem, -1), ['l', 's', 'z'], true)) {
                    return substr($stem, 0, -1);
                }

                return self::measure($stem) === 1 && self::cvc($stem) ? $stem . 'e' : $stem;
            }
        }

        return $w;
    }

    private static function step1c(string $w): string
    {
        return str_ends_with($w, 'y') && self::hasVowel(substr($w, 0, -1)) ? substr($w, 0, -1) . 'i' : $w;
    }

    /** @param array<string, string> $rules longest suffix wins; replaced when the stem has measure > $min. */
    private static function replace(string $w, array $rules, int $min): string
    {
        uksort($rules, static fn ($a, $b) => strlen($b) <=> strlen($a));

        foreach ($rules as $suffix => $to) {
            if (str_ends_with($w, $suffix)) {
                $stem = substr($w, 0, -strlen($suffix));

                return self::measure($stem) > $min ? $stem . $to : $w;
            }
        }

        return $w;
    }

    private static function step4(string $w): string
    {
        $suffixes = self::STEP4;
        usort($suffixes, static fn ($a, $b) => strlen($b) <=> strlen($a));

        foreach ($suffixes as $suffix) {
            if (str_ends_with($w, $suffix)) {
                $stem = substr($w, 0, -strlen($suffix));

                if ($suffix === 'ion' && !in_array(substr($stem, -1), ['s', 't'], true)) {
                    return $w;
                }

                return self::measure($stem) > 1 ? $stem : $w;
            }
        }

        return $w;
    }

    private static function step5(string $w): string
    {
        if (str_ends_with($w, 'e')) {
            $stem = substr($w, 0, -1);
            $m = self::measure($stem);

            if ($m > 1 || ($m === 1 && !self::cvc($stem))) {
                $w = $stem;
            }
        }

        if (str_ends_with($w, 'll') && self::measure($w) > 1) {
            $w = substr($w, 0, -1);
        }

        return $w;
    }

    private static function consonant(string $w, int $i): bool
    {
        $c = $w[$i];

        if (str_contains('aeiou', $c)) {
            return false;
        }

        return $c === 'y' ? ($i === 0 || !self::consonant($w, $i - 1)) : true;
    }

    /** m in [C](VC)^m[V]. */
    private static function measure(string $s): int
    {
        $n = 0;
        $i = 0;
        $len = strlen($s);

        while ($i < $len && self::consonant($s, $i)) {
            $i++;
        }

        while ($i < $len) {
            while ($i < $len && !self::consonant($s, $i)) {
                $i++;
            }

            if ($i >= $len) {
                break;
            }

            while ($i < $len && self::consonant($s, $i)) {
                $i++;
            }
            $n++;
        }

        return $n;
    }

    private static function hasVowel(string $s): bool
    {
        for ($i = 0, $len = strlen($s); $i < $len; $i++) {
            if (!self::consonant($s, $i)) {
                return true;
            }
        }

        return false;
    }

    private static function doubleConsonant(string $s): bool
    {
        $len = strlen($s);

        return $len >= 2 && $s[$len - 1] === $s[$len - 2] && self::consonant($s, $len - 1);
    }

    private static function cvc(string $s): bool
    {
        $len = strlen($s);

        return $len >= 3 && self::consonant($s, $len - 3) && !self::consonant($s, $len - 2) && self::consonant($s, $len - 1)
            && !in_array($s[$len - 1], ['w', 'x', 'y'], true);
    }
}
