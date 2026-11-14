<?php

namespace App\Support\Search;

/**
 * A light Ukrainian stemmer in the spirit of the Snowball Russian/Ukrainian ones: the longest
 * case, number, adjective or verb ending inside RV (the part after the first vowel) is cut,
 * then a reflexive -ся/-сь and a soft sign. Finally the і/о/е of a closed last syllable is
 * folded to "о", so vowel alternation lines up: Львів/Львові/Львова → львов, біг/бігу/бігом/
 * бігати → бог, осінь/осені → осон, робота/роботі/роботою → робот.
 *
 * The output is an index key, not a word: it is only compared with other keys.
 */
final class UkrainianStemmer
{
    private const VOWELS = 'аеиоуюяіїєыэ';

    /** Noun, adjective, participle and verb endings (Snowball-style lists, merged), longest first. */
    private const ENDINGS = [
        // 4+
        'ившись', 'ивши', 'вшись', 'ується', 'ються', 'ється', 'ться', 'ючись', 'ячись',
        // 3
        'ами', 'ями', 'ові', 'еві', 'єві', 'ого', 'ому', 'ими', 'іми', 'йми', 'ова', 'ове', 'ові', 'иям', 'иях', 'ием',
        'ати', 'яти', 'ити', 'іти', 'ать', 'ять', 'али', 'али', 'или', 'ила', 'ило', 'ала', 'ало', 'ячи', 'учи', 'ючи', 'вши',
        'ємо', 'имо', 'емо', 'ете', 'єте', 'ите', 'уть', 'ють', 'ять', 'ить', 'іть', 'еть', 'ння', 'ною', 'ної', 'ний', 'ного',
        // 2
        'ах', 'ях', 'ам', 'ям', 'ом', 'ем', 'єм', 'им', 'ім', 'их', 'іх', 'ою', 'ею', 'єю', 'ий', 'ій', 'їй', 'ої', 'ей', 'ой',
        'ів', 'їв', 'ов', 'ев', 'ав', 'ив', 'ла', 'ло', 'ли', 'ти', 'ть', 'ши', 'ме', 'ию', 'ью', 'ия', 'ья', 'єє', 'еє',
        'ся', 'сь', 'си',
        // 1
        'а', 'я', 'о', 'е', 'є', 'и', 'і', 'ї', 'у', 'ю', 'й', 'ь', 'ы',
    ];

    /** @var null|list<string> */
    private static ?array $sorted = null;

    public static function stem(string $word): string
    {
        $word = str_replace(['ё', 'ъ'], ['е', ''], $word);

        if (!preg_match('/^\p{Cyrillic}+$/u', $word)) {
            return $word;
        }

        $chars = mb_str_split($word);
        $first = null;

        foreach ($chars as $i => $c) {
            if (str_contains(self::VOWELS, $c)) {
                $first = $i;

                break;
            }
        }

        if ($first !== null) {
            $prefix = implode('', array_slice($chars, 0, $first + 1));
            $rv = implode('', array_slice($chars, $first + 1));
            // Reflexive first (бігався → бігав → біг), then one ending.
            $rv = self::cut($rv, ['ся', 'сь']);
            $rv = self::cut($rv, self::endings());
            $rv = self::cut($rv, ['ь']);
            // Keep at least two letters of the word.
            $word = mb_strlen($prefix . $rv) >= 2 ? $prefix . $rv : $word;
        }

        return self::foldAlternation($word);
    }

    /** The last і/е/о followed only by consonants becomes "о" (Львів ~ Львова, кінь ~ коня). */
    public static function foldAlternation(string $stem): string
    {
        $chars = mb_str_split($stem);

        for ($i = count($chars) - 1; $i >= 0; $i--) {
            if (str_contains(self::VOWELS, $chars[$i])) {
                if ($i < count($chars) - 1 && in_array($chars[$i], ['і', 'е', 'о'], true)) {
                    $chars[$i] = 'о';
                }

                break;
            }
        }

        return implode('', $chars);
    }

    /** @param list<string> $endings longest first */
    private static function cut(string $rv, array $endings): string
    {
        foreach ($endings as $ending) {
            if ($ending !== '' && str_ends_with($rv, $ending)) {
                return mb_substr($rv, 0, mb_strlen($rv) - mb_strlen($ending));
            }
        }

        return $rv;
    }

    /** @return list<string> */
    private static function endings(): array
    {
        if (self::$sorted === null) {
            $list = array_values(array_unique(self::ENDINGS));
            usort($list, static fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
            self::$sorted = $list;
        }

        return self::$sorted;
    }
}
