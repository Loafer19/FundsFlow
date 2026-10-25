<?php

namespace App\Support;

/**
 * The assistant's light Markdown → Telegram HTML (parse_mode HTML), split into messages.
 *
 * Supported: **bold** / __bold__, *italic* / _italic_, `code`, ``` code blocks ```, [text](https://…),
 * "# heading" (bold), "- item" / "* item" bullets (•). Everything else is escaped text.
 * Blocks (paragraphs, code blocks) are converted one by one, so every part of a split
 * message has balanced tags and no cut entity.
 */
final class TelegramHtml
{
    /** Telegram's limit is 4096 characters after entity parsing; HTML length is a safe upper bound. */
    public const LIMIT = 4096;

    public const MAX_PARTS = 5;

    /**
     * @return list<string> HTML messages, each at most $limit characters
     */
    public static function split(string $markdown, int $limit = self::LIMIT): array
    {
        $chunks = [];
        foreach (self::blocks($markdown) as $block) {
            foreach (self::fit($block, $limit) as $html) {
                $chunks[] = $html;
            }
        }

        // Pack blocks into messages at paragraph boundaries.
        $parts = [];
        $current = '';
        foreach ($chunks as $html) {
            $candidate = $current === '' ? $html : $current . "\n\n" . $html;
            if (mb_strlen($candidate) <= $limit) {
                $current = $candidate;

                continue;
            }
            if ($current !== '') {
                $parts[] = $current;
            }
            $current = $html;
        }
        if ($current !== '') {
            $parts[] = $current;
        }

        if (count($parts) > self::MAX_PARTS) {
            $parts = array_slice($parts, 0, self::MAX_PARTS);
            $last = $parts[self::MAX_PARTS - 1];
            $parts[self::MAX_PARTS - 1] = mb_strlen($last) + 2 <= $limit ? $last . "\n…" : $last;
        }

        return $parts;
    }

    public static function convert(string $markdown): string
    {
        return implode("\n\n", array_map(self::convertBlock(...), self::blocks($markdown)));
    }

    /**
     * Same text without markup, for the plain-text fallback.
     */
    public static function toPlain(string $html): string
    {
        $text = preg_replace('/<a href="([^"]*)">(.*?)<\/a>/su', '$2 ($1)', $html) ?? $html;

        return html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Markdown split into paragraphs; a fenced code block is one block even with blank lines.
     *
     * @return list<array{code: bool, text: string}>
     */
    private static function blocks(string $markdown): array
    {
        $markdown = str_replace(["\r\n", "\r"], "\n", trim($markdown));
        $blocks = [];
        $buffer = [];
        $inCode = false;

        $flush = static function () use (&$buffer, &$blocks, &$inCode): void {
            $text = implode("\n", $buffer);
            if (trim($text) !== '') {
                $blocks[] = ['code' => $inCode, 'text' => $inCode ? $text : trim($text)];
            }
            $buffer = [];
        };

        foreach (explode("\n", $markdown) as $line) {
            if (preg_match('/^\s*```/', $line)) {
                $flush();
                $inCode = !$inCode;

                continue;
            }
            if (!$inCode && trim($line) === '') {
                $flush();

                continue;
            }
            $buffer[] = $line;
        }
        $flush();

        return $blocks;
    }

    /**
     * @param array{code: bool, text: string} $block
     */
    private static function convertBlock(array $block): string
    {
        if ($block['code']) {
            return '<pre><code>' . self::escape($block['text']) . '</code></pre>';
        }

        return implode("\n", array_map(self::convertLine(...), explode("\n", $block['text'])));
    }

    /**
     * One block as HTML pieces no longer than $limit: whole when it fits, else by lines,
     * and a line longer than that is cut as plain text.
     *
     * @param array{code: bool, text: string} $block
     * @return list<string>
     */
    private static function fit(array $block, int $limit): array
    {
        $html = self::convertBlock($block);
        if (mb_strlen($html) <= $limit) {
            return [$html];
        }

        $wrap = $block['code'] ? ['<pre><code>', '</code></pre>'] : ['', ''];
        $room = $limit - mb_strlen($wrap[0] . $wrap[1]);
        $pieces = [];
        $current = '';
        foreach (explode("\n", $block['text']) as $line) {
            $lineHtml = $block['code'] ? self::escape($line) : self::convertLine($line);
            if (mb_strlen($lineHtml) > $room) {
                // A single huge line: no formatting, cut between characters (never inside an entity).
                if ($current !== '') {
                    $pieces[] = $current;
                    $current = '';
                }
                foreach (self::cutPlain($line, $room) as $cut) {
                    $pieces[] = $cut;
                }

                continue;
            }
            $candidate = $current === '' ? $lineHtml : $current . "\n" . $lineHtml;
            if (mb_strlen($candidate) > $room) {
                $pieces[] = $current;
                $candidate = $lineHtml;
            }
            $current = $candidate;
        }
        if ($current !== '') {
            $pieces[] = $current;
        }

        return array_map(static fn (string $p): string => $wrap[0] . $p . $wrap[1], $pieces);
    }

    /**
     * @return list<string>
     */
    private static function cutPlain(string $text, int $room): array
    {
        $out = [];
        $current = '';
        foreach (mb_str_split($text) as $char) {
            $escaped = self::escape($char);
            if (mb_strlen($current) + mb_strlen($escaped) > $room) {
                $out[] = $current;
                $current = '';
            }
            $current .= $escaped;
        }
        if ($current !== '') {
            $out[] = $current;
        }

        return $out;
    }

    private static function convertLine(string $line): string
    {
        if (preg_match('/^\s{0,3}#{1,6}\s+(.+)$/u', $line, $m)) {
            return '<b>' . self::inline(rtrim($m[1], " #")) . '</b>';
        }
        if (preg_match('/^(\s*)[-*+]\s+(.+)$/u', $line, $m)) {
            return $m[1] . '• ' . self::inline($m[2]);
        }

        return self::inline($line);
    }

    /**
     * Inline code and links are cut out first (their contents are not formatted), the rest is
     * escaped and then bold / italic are applied.
     */
    private static function inline(string $text): string
    {
        $slots = [];
        $hold = static function (string $html) use (&$slots): string {
            $slots[] = $html;

            return "\u{E000}" . (count($slots) - 1) . "\u{E001}";
        };

        $text = preg_replace_callback('/`([^`\n]+)`/u', static fn (array $m): string => $hold('<code>' . self::escape($m[1]) . '</code>'), $text) ?? $text;
        $text = preg_replace_callback(
            '/\[([^\]\n]+)\]\((https?:\/\/[^\s)]+)\)/u',
            static fn (array $m): string => $hold('<a href="' . htmlspecialchars($m[2], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">' . self::escape($m[1]) . '</a>'),
            $text,
        ) ?? $text;

        $text = self::escape($text);
        $text = preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/u', '<b>$1</b>', $text) ?? $text;
        $text = preg_replace('/(?<![\p{L}\p{N}_])__(?=\S)(.+?)(?<=\S)__(?![\p{L}\p{N}_])/u', '<b>$1</b>', $text) ?? $text;
        $text = preg_replace('/(?<![*\p{L}\p{N}])\*(?=[^\s*])([^*\n]+?)(?<=[^\s*])\*(?![*\p{L}\p{N}])/u', '<i>$1</i>', $text) ?? $text;
        $text = preg_replace('/(?<![\p{L}\p{N}_])_(?=[^\s_])([^_\n]+?)(?<=[^\s_])_(?![\p{L}\p{N}_])/u', '<i>$1</i>', $text) ?? $text;

        return preg_replace_callback("/\u{E000}(\\d+)\u{E001}/u", static fn (array $m): string => $slots[(int) $m[1]] ?? '', $text) ?? $text;
    }
}
