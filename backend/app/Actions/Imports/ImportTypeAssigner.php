<?php

namespace App\Actions\Imports;

use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\TransactionType;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Which transaction type each import row gets. In order of priority:
 * a type picked on the row (options.type_lines, by line, so it survives row rebuilds),
 * then a batch rule ("rows with OKKO → Fuel", or every row when the text is empty; the last rule wins),
 * then a suggestion from the merchant text and MCC (web previews only, expenses only).
 */
class ImportTypeAssigner
{
    public const SOURCE_SUGGESTED = 'suggested';

    public const SOURCE_RULE = 'rule';

    public const SOURCE_MANUAL = 'manual';

    /**
     * Starter-type hints: a type whose name contains one of the "names" is suggested
     * for rows whose note matches one of the "words" (word start) or whose MCC is listed.
     */
    private const HINTS = [
        [
            'names' => ['fuel', 'паливо', 'пальне', 'бензин', 'топливо', 'азс'],
            'words' => ['okko', 'окко', 'wog', 'вог', 'upg', 'socar', 'сокар', 'shell', 'klo', 'брсм', 'brsm', 'amic', 'авіас', 'avias', 'chipo', 'мангуст', 'orlen', 'bp ', 'азс', 'fuel', 'petrol', 'gas station', 'заправка'],
            'mcc' => [5541, 5542, 5983, 5172],
        ],
        [
            'names' => ['grocer', 'продукт', 'супермаркет', 'groceries'],
            'words' => ['silpo', 'сільпо', 'сильпо', 'atb', 'атб', 'novus', 'новус', 'fora', 'фора', 'auchan', 'ашан', 'metro cash', 'метро кеш', 'varus', 'варус', 'еко маркет', 'ekomarket', 'tavria', 'таврія', 'lidl', 'biedronka', 'aldi', 'tesco', 'spar', 'nash kray', 'наш край', 'kopiyka', 'копійка', 'velmart', 'вельмарт', 'mega market', 'megamarket', 'rukavychka', 'рукавичка', 'blyzenko', 'близенько', 'supermarket', 'grocery'],
            'mcc' => [5411, 5422, 5441, 5451, 5462, 5499],
        ],
        [
            'names' => ['subscri', 'підписк', 'подписк'],
            'words' => ['netflix', 'spotify', 'youtube', 'apple.com', 'apple com', 'icloud', 'google one', 'google storage', 'chatgpt', 'openai', 'claude', 'anthropic', 'midjourney', 'megogo', 'мегого', 'sweet.tv', 'sweettv', 'kyivstar tv', 'patreon', 'disney', 'hbo', 'deezer', 'notion', 'figma', 'adobe', 'dropbox', 'github', 'jetbrains', 'duolingo', 'canva', 'telegram premium', 'x premium', 'grok'],
            'mcc' => [4899, 5815, 5816, 5817, 5818, 5968],
        ],
        [
            'names' => ['restaurant', 'ресторан', 'cafe', 'кафе', 'eating out', 'їжа поза'],
            'words' => ['mcdonald', 'макдональдс', 'kfc', 'burger', 'бургер', 'puzata', 'пузата', 'glovo', 'bolt food', 'boltfood', 'uber eats', 'wolt', 'raketa', 'ракета', 'starbucks', 'aroma kava', 'арома кава', 'lviv croissants', 'львівські круасани', 'pizza', 'піца', 'sushi', 'суші', 'cafe', 'café', 'кафе', 'coffee', 'кава', 'кав\'ярня', 'restaurant', 'ресторан', 'bar ', 'pub ', 'паб'],
            'mcc' => [5811, 5812, 5813, 5814],
        ],
    ];

    /**
     * Writes transaction_type_id / type_source on every row of the batch.
     */
    public function assign(User $user, ImportBatch $batch): void
    {
        $types = $user->transactionTypes()->get(['id', 'name', 'emoji'])->keyBy('id');
        $options = is_array($batch->options) ? $batch->options : [];
        $lines = is_array($options['type_lines'] ?? null) ? $options['type_lines'] : [];
        $rules = is_array($options['type_rules'] ?? null) ? $options['type_rules'] : [];
        $suggest = $batch->source === 'web' && $types->isNotEmpty();
        $hints = $suggest ? $this->hintsFor($types) : [];

        foreach ($batch->rows()->get() as $row) {
            [$typeId, $source] = $this->pick($row, $types, $lines, $rules, $hints);

            if ((int) $row->transaction_type_id !== (int) $typeId || $row->type_source !== $source) {
                $row->forceFill(['transaction_type_id' => $typeId, 'type_source' => $source])->save();
            }
        }
    }

    /**
     * @param array<string, mixed> $rule {match?: string, type_id: int|null}
     */
    public function addRule(ImportBatch $batch, array $rule): void
    {
        $options = is_array($batch->options) ? $batch->options : [];
        $match = mb_strtolower(trim((string) ($rule['match'] ?? '')));
        $typeId = isset($rule['type_id']) ? (int) $rule['type_id'] : 0;
        // A rule for every row replaces the earlier ones and the row picks: "make all of these Fuel".
        $rules = $match === '' ? [] : array_values(array_filter(
            is_array($options['type_rules'] ?? null) ? $options['type_rules'] : [],
            static fn ($r) => is_array($r) && ($r['match'] ?? '') !== $match,
        ));
        $rules[] = ['match' => $match, 'type_id' => $typeId];
        $options['type_rules'] = $rules;

        if ($match === '') {
            $options['type_lines'] = [];
        }
        $batch->options = $options;
        $batch->save();
    }

    public function setLine(ImportBatch $batch, int $line, ?int $typeId): void
    {
        $options = is_array($batch->options) ? $batch->options : [];
        $lines = is_array($options['type_lines'] ?? null) ? $options['type_lines'] : [];
        $lines[(string) $line] = (int) ($typeId ?? 0);
        $options['type_lines'] = $lines;
        $batch->options = $options;
        $batch->save();
    }

    /**
     * @param Collection<int, TransactionType> $types
     * @param array<string, int> $lines
     * @param list<array{match?: string, type_id?: int}> $rules
     * @param list<array{type_id: int, words: list<string>, mcc: list<int>}> $hints
     * @return array{0: ?int, 1: ?string}
     */
    private function pick(ImportRow $row, Collection $types, array $lines, array $rules, array $hints): array
    {
        $line = (string) $row->line;

        if (array_key_exists($line, $lines)) {
            $id = (int) $lines[$line];

            return $id > 0 && $types->has($id) ? [$id, self::SOURCE_MANUAL] : [null, self::SOURCE_MANUAL];
        }

        $text = $this->text($row);

        for ($i = count($rules) - 1; $i >= 0; $i--) {
            $rule = $rules[$i];
            $match = (string) ($rule['match'] ?? '');

            if ($match === '' || str_contains($text, $match)) {
                $id = (int) ($rule['type_id'] ?? 0);

                return $id > 0 && $types->has($id) ? [$id, self::SOURCE_RULE] : [null, self::SOURCE_RULE];
            }
        }

        if ($hints === [] || $row->amount === null || (float) $row->amount >= 0) {
            return [null, null];
        }

        $mcc = $this->mcc($row);

        foreach ($hints as $hint) {
            if ($mcc !== null && in_array($mcc, $hint['mcc'], true)) {
                return [$hint['type_id'], self::SOURCE_SUGGESTED];
            }
        }

        foreach ($hints as $hint) {
            foreach ($hint['words'] as $word) {
                if (preg_match('/(^|[^\p{L}\p{N}])' . preg_quote($word, '/') . '/u', $text)) {
                    return [$hint['type_id'], self::SOURCE_SUGGESTED];
                }
            }
        }

        return [null, null];
    }

    /**
     * Starter hints for the types this user has (matched by name), plus each other type's own name.
     *
     * @param Collection<int, TransactionType> $types
     * @return list<array{type_id: int, words: list<string>, mcc: list<int>}>
     */
    private function hintsFor(Collection $types): array
    {
        $out = [];
        $used = [];

        foreach (self::HINTS as $hint) {
            foreach ($types as $type) {
                $name = mb_strtolower((string) $type->name);

                foreach ($hint['names'] as $needle) {
                    if (str_contains($name, $needle)) {
                        $out[] = ['type_id' => (int) $type->id, 'words' => $hint['words'], 'mcc' => $hint['mcc']];
                        $used[(int) $type->id] = true;

                        continue 3;
                    }
                }
            }
        }

        foreach ($types as $type) {
            $name = mb_strtolower(trim((string) $type->name));

            if (!isset($used[(int) $type->id]) && mb_strlen($name) >= 4) {
                $out[] = ['type_id' => (int) $type->id, 'words' => [$name], 'mcc' => []];
            }
        }

        return $out;
    }

    private function text(ImportRow $row): string
    {
        return mb_strtolower(trim(($row->note ?? '') . ' ' . ($row->category ?? '')));
    }

    private function mcc(ImportRow $row): ?int
    {
        $raw = is_array($row->raw) ? $row->raw : [];

        foreach ($raw as $key => $value) {
            if (is_string($key) && preg_match('/\bmcc\b/i', $key) && is_numeric($value)) {
                return (int) $value;
            }
        }

        return null;
    }
}
