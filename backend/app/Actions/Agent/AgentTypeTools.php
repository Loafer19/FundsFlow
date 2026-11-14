<?php

namespace App\Actions\Agent;

use App\Actions\TransactionTypes\SaveTransactionTypeAction;
use App\Models\TransactionType;
use App\Models\User;
use App\Support\TransactionFields;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Transaction type tools for every agent transport (wired in through AgentPlanningTools):
 * list types, and Confirm/Cancel proposals to create (several at once), edit and archive them.
 * Schemas are validated in prepare() with TransactionFields::normalizeSchema (the EventFields-style
 * entry point the editor and API use), and saved after Confirm through SaveTransactionTypeAction.
 */
class AgentTypeTools
{
    public const READ_TOOLS = ['list_transaction_types'];

    public const STAGING_TOOLS = ['create_transaction_types', 'update_transaction_type', 'archive_transaction_type'];

    public const MAX_PER_PROPOSAL = 10;

    /** One line for both system prompts. */
    public const PROMPT_RULE = '- Transaction types (Fuel, Groceries…: a type has custom fields, e.g. liters, price per liter, station, a computed total that fills the amount): you can manage them. list_transaction_types shows them with field keys. To create one or several ("create these types: …") call create_transaction_types once with all of them; to add/rename/hide fields, change options or default tags call update_transaction_type; archive_transaction_type archives one. Each stages one Confirm card. Field kinds: number (unit), rating (max 3/5/10), duration, boolean, select (options), multiselect (options), text, date, computed (formula over number/rating/duration/boolean keys of the same type with + - * / and parentheses, e.g. liters * price_per_liter; fill_amount=true makes it the transaction amount, at most one per type). Give snake_case keys; formulas use those keys. Default tags must be existing tag titles.';

    /** The Manage Types palette; a proposal without a color gets the next unused one. */
    private const COLORS = ['#f59e0b', '#ef4444', '#ec4899', '#a855f7', '#6366f1', '#3b82f6', '#06b6d4', '#14b8a6', '#22c55e', '#6b7280'];

    private const KIND_LABELS = [
        'number' => 'Number',
        'rating' => 'Rating',
        'duration' => 'Duration',
        'boolean' => 'Yes / No',
        'select' => 'One of',
        'multiselect' => 'Several of',
        'text' => 'Text',
        'date' => 'Date',
        'computed' => 'Computed',
    ];

    public function __construct(private readonly SaveTransactionTypeAction $save) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function definitions(): array
    {
        $fn = AgentToolExecutor::fn(...);
        $field = [
            'type' => 'object',
            'properties' => [
                'key' => ['type' => 'string', 'description' => 'snake_case key, e.g. price_per_liter (formulas use keys)'],
                'label' => ['type' => 'string', 'description' => 'Shown name, e.g. Price per liter (English unless the user named it otherwise)'],
                'type' => ['type' => 'string', 'enum' => TransactionFields::TYPES],
                'unit' => ['type' => 'string', 'description' => 'number/computed: l, km, ₴, kWh…'],
                'options' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'select/multiselect choices'],
                'formula' => ['type' => 'string', 'description' => 'computed: e.g. liters * price_per_liter'],
                'fill_amount' => ['type' => 'boolean', 'description' => 'computed: the result is the transaction amount'],
                'required' => ['type' => 'boolean'],
                'min' => ['type' => 'number'],
                'max' => ['type' => 'number', 'description' => 'number: max; rating: scale 3, 5 or 10'],
            ],
            'required' => ['label', 'type'],
        ];
        $typeRef = ['type' => 'string', 'description' => 'Type name (or id) from list_transaction_types'];
        $tags = ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Existing tag titles added automatically to transactions of this type'];

        return [
            $fn('list_transaction_types', 'List the user\'s transaction types with id, emoji, fields (key, label, kind, unit, options, formula, fill_amount, hidden) and default tags. include_archived=true also lists archived ones.', [
                'type' => 'object',
                'properties' => ['include_archived' => ['type' => 'boolean']],
            ]),
            $fn('create_transaction_types', 'Propose creating 1–' . self::MAX_PER_PROPOSAL . ' transaction types at once. Stages one Confirm/Cancel card; nothing is created until the user confirms.', [
                'type' => 'object',
                'properties' => [
                    'types' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'name' => ['type' => 'string'],
                                'emoji' => ['type' => 'string', 'description' => 'One emoji, e.g. ⛽'],
                                'color' => ['type' => 'string', 'description' => '#rrggbb'],
                                'fields' => ['type' => 'array', 'items' => $field],
                                'default_tags' => $tags,
                            ],
                            'required' => ['name', 'fields'],
                        ],
                    ],
                ],
                'required' => ['types'],
            ]),
            $fn('update_transaction_type', 'Propose changes to one transaction type: rename it, change emoji/color, add fields, rename fields, hide fields (values are kept), restore hidden fields, add or remove select options, replace default tags. Stages Confirm/Cancel.', [
                'type' => 'object',
                'properties' => [
                    'type' => $typeRef,
                    'name' => ['type' => 'string', 'description' => 'New name'],
                    'emoji' => ['type' => 'string'],
                    'color' => ['type' => 'string'],
                    'add_fields' => ['type' => 'array', 'items' => $field],
                    'rename_fields' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['key' => ['type' => 'string'], 'label' => ['type' => 'string']], 'required' => ['key', 'label']]],
                    'hide_fields' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Field keys to remove from the form'],
                    'restore_fields' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Hidden field keys to show again'],
                    'add_options' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['key' => ['type' => 'string'], 'options' => ['type' => 'array', 'items' => ['type' => 'string']]], 'required' => ['key', 'options']]],
                    'remove_options' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['key' => ['type' => 'string'], 'options' => ['type' => 'array', 'items' => ['type' => 'string']]], 'required' => ['key', 'options']]],
                    'default_tags' => $tags + ['description' => 'Replaces the default tags (empty list clears them)'],
                ],
                'required' => ['type'],
            ]),
            $fn('archive_transaction_type', 'Propose archiving a transaction type (its transactions keep the type and values; it can be restored in Manage Types). Stages Confirm/Cancel.', [
                'type' => 'object',
                'properties' => ['type' => $typeRef],
                'required' => ['type'],
            ]),
        ];
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function read(User $user, array $args): array
    {
        $query = $user->transactionTypes()->orderBy('name');

        if (($args['include_archived'] ?? false) === true) {
            $query->withTrashed();
        }
        $tagNames = $user->tags()->pluck('title', 'id');

        $types = $query->get()->map(fn (TransactionType $type): array => [
            'id' => (int) $type->id,
            'name' => $type->name,
            'emoji' => $type->emoji,
            'archived' => $type->trashed(),
            'fields' => array_map(static fn (array $f): array => array_filter([
                'key' => $f['key'],
                'label' => $f['label'],
                'kind' => $f['type'],
                'unit' => $f['unit'] ?? null,
                'options' => $f['options'] ?? null,
                'formula' => $f['formula'] ?? null,
                'fill_amount' => ($f['fill_amount'] ?? false) ?: null,
                'required' => ($f['required'] ?? false) ?: null,
                'hidden' => ($f['hidden'] ?? false) ?: null,
            ], static fn ($v) => $v !== null), is_array($type->fields) ? $type->fields : []),
            'default_tags' => collect($type->defaultTagIds())->map(fn (int $id) => $tagNames[$id] ?? null)->filter()->values()->all(),
        ])->all();

        return ['count' => count($types), 'types' => $types];
    }

    /**
     * @param array<string, mixed> $args
     * @return array{payload: array<string, mixed>, preview: string, card: array<string, mixed>}
     */
    public function prepare(User $user, string $name, array $args): array
    {
        return match ($name) {
            'create_transaction_types' => $this->prepareCreate($user, $args),
            'update_transaction_type' => $this->prepareUpdate($user, $args),
            'archive_transaction_type' => $this->prepareArchive($user, $args),
            default => throw new InvalidArgumentException("Unknown staging tool: {$name}"),
        };
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{ok: bool, message: string, summary: string}
     */
    public function apply(User $user, string $action, array $payload): array
    {
        try {
            return match ($action) {
                'create_transaction_types' => $this->applyCreate($user, $payload),
                'update_transaction_type' => $this->applyUpdate($user, $payload),
                'archive_transaction_type' => $this->applyArchive($user, $payload),
                default => ['ok' => false, 'message' => 'Nothing to confirm.', 'summary' => 'Nothing to confirm.'],
            };
        } catch (ValidationException $e) {
            // Something changed since the preview (a type with that name was added meanwhile…).
            $message = self::errors($e);

            return ['ok' => false, 'message' => 'Not saved: ' . $message, 'summary' => 'Type change failed: ' . $message];
        }
    }

    /** "Number · l", "One of: A-95, Diesel", "= liters × price_per_liter → amount". */
    public static function kindText(array $field): string
    {
        $kind = self::KIND_LABELS[$field['type']] ?? $field['type'];

        return match ($field['type']) {
            'number' => $kind . (($field['unit'] ?? null) ? ' · ' . $field['unit'] : ''),
            'rating' => 'Rating 1–' . ($field['max'] ?? 5),
            'select', 'multiselect' => $kind . ': ' . implode(', ', $field['options'] ?? []),
            'computed' => '= ' . str_replace('*', '×', (string) ($field['formula'] ?? '')) . (($field['unit'] ?? null) ? ' ' . $field['unit'] : '') . (($field['fill_amount'] ?? false) ? ' → amount' : ''),
            default => $kind,
        } . (($field['required'] ?? false) ? ' · required' : '');
    }

    /** The card as plain text (Telegram preview, model summary). */
    public static function cardText(array $card): string
    {
        $lines = [$card['title'] . ':'];

        foreach ($card['rows'] as $row) {
            $lines[] = '';
            $lines[] = trim(($row['emoji'] ?? '') . ' ' . $row['name']);

            foreach ($row['changes'] ?? [] as $change) {
                $lines[] = '  ✎ ' . $change;
            }

            if ($card['mode'] !== 'archive') {
                foreach ($row['fields'] as $f) {
                    $mark = match ($f['mark']) {
                        'new' => ' (new)',
                        'hidden' => ' (hidden)',
                        'renamed' => ' (renamed)',
                        'restored' => ' (shown again)',
                        default => '',
                    };
                    $lines[] = '  • ' . $f['label'] . ' — ' . $f['kind'] . $mark;
                }
                $lines[] = '  🏷 ' . ($row['tags'] === [] ? 'No default tags' : implode(' · ', $row['tags']));
            }

            foreach ($row['notes'] ?? [] as $note) {
                $lines[] = '  ' . $note;
            }
        }

        return implode("\n", $lines);
    }

    // ---------------------------------------------------------------------

    /**
     * @param array<string, mixed> $args
     */
    private function prepareCreate(User $user, array $args): array
    {
        $raw = array_values(array_filter(is_array($args['types'] ?? null) ? $args['types'] : [], 'is_array'));

        if ($raw === []) {
            throw new InvalidArgumentException('types must list at least one type');
        }

        if (count($raw) > self::MAX_PER_PROPOSAL) {
            throw new InvalidArgumentException('at most ' . self::MAX_PER_PROPOSAL . ' types per proposal');
        }

        $existing = $user->transactionTypes()->pluck('name')->map(fn ($n) => mb_strtolower((string) $n))->all();

        if (count($existing) + count($raw) > SaveTransactionTypeAction::MAX_TYPES) {
            throw new InvalidArgumentException('the account can have at most ' . SaveTransactionTypeAction::MAX_TYPES . ' types');
        }

        $types = [];
        $rows = [];
        $errors = [];
        $seen = [];
        $usedColors = $user->transactionTypes()->pluck('color')->filter()->map(fn ($c) => strtolower((string) $c))->all();

        foreach ($raw as $i => $item) {
            $name = mb_substr(trim((string) ($item['name'] ?? '')), 0, 64);

            if ($name === '') {
                $errors[] = "type {$i}: name is required";

                continue;
            }
            $lower = mb_strtolower($name);

            if (in_array($lower, $existing, true)) {
                $errors[] = "you already have a type called {$name} (use update_transaction_type to change it)";

                continue;
            }

            if (isset($seen[$lower])) {
                $errors[] = "{$name} is listed twice";

                continue;
            }
            $seen[$lower] = true;

            try {
                $fields = TransactionFields::normalizeSchema(self::fieldInput($item['fields'] ?? []));
            } catch (ValidationException $e) {
                $errors[] = "{$name}: " . self::errors($e);

                continue;
            }
            [$tagIds, $missing] = $this->tagIds($user, $item['default_tags'] ?? []);

            $color = self::color($item['color'] ?? null)
                ?? (array_values(array_diff(self::COLORS, $usedColors))[0] ?? self::COLORS[count($types) % count(self::COLORS)]);
            $usedColors[] = $color;
            $type = [
                'name' => $name,
                'emoji' => self::emoji($item['emoji'] ?? null),
                'color' => $color,
                'fields' => $fields,
                'default_tag_ids' => $tagIds,
            ];
            $types[] = $type;
            $rows[] = $this->cardRow($user, $type, notes: $missing !== [] ? ['Tag not found, skipped: ' . implode(', ', $missing)] : []);
        }

        if ($errors !== []) {
            throw new InvalidArgumentException(implode('; ', $errors));
        }

        $card = [
            'type' => 'transaction_types',
            'mode' => 'create',
            'title' => count($types) === 1 ? 'New type' : 'New types (' . count($types) . ')',
            'rows' => $rows,
        ];

        return ['payload' => ['types' => $types], 'preview' => self::cardText($card), 'card' => $card];
    }

    /**
     * @param array<string, mixed> $args
     */
    private function prepareUpdate(User $user, array $args): array
    {
        $type = $this->findType($user, $args['type'] ?? null);
        $current = is_array($type->fields) ? $type->fields : [];
        $byKey = [];

        foreach ($current as $f) {
            $byKey[$f['key']] = $f;
        }
        $changes = [];
        $marks = [];

        $restore = array_map('strval', is_array($args['restore_fields'] ?? null) ? $args['restore_fields'] : []);
        $hide = array_map('strval', is_array($args['hide_fields'] ?? null) ? $args['hide_fields'] : []);

        foreach ([...$restore, ...$hide] as $key) {
            if (!isset($byKey[$key])) {
                throw new InvalidArgumentException("no field with key {$key}; keys: " . implode(', ', array_keys($byKey)));
            }
        }

        // The editor payload: visible fields (plus restored ones) in order; what is left out ends hidden.
        $fields = [];

        foreach ($current as $f) {
            $key = $f['key'];

            if (in_array($key, $hide, true)) {
                $marks[$key] = 'hidden';
                $changes[] = "Hide {$f['label']}";

                continue;
            }

            if (($f['hidden'] ?? false) && !in_array($key, $restore, true)) {
                continue;
            }

            if ($f['hidden'] ?? false) {
                $marks[$key] = 'restored';
                $changes[] = "Show {$f['label']} again";
            }
            $fields[$key] = [...$f, 'hidden' => false];
        }

        foreach (is_array($args['rename_fields'] ?? null) ? $args['rename_fields'] : [] as $rename) {
            $key = (string) ($rename['key'] ?? '');
            $label = trim((string) ($rename['label'] ?? ''));

            if (!isset($fields[$key]) || $label === '') {
                throw new InvalidArgumentException("cannot rename {$key}: not a visible field (or empty label)");
            }
            $changes[] = "Rename {$fields[$key]['label']} → {$label}";
            $fields[$key]['label'] = $label;
            $marks[$key] ??= 'renamed';
        }

        foreach (['add_options' => true, 'remove_options' => false] as $arg => $adding) {
            foreach (is_array($args[$arg] ?? null) ? $args[$arg] : [] as $change) {
                $key = (string) ($change['key'] ?? '');
                $options = array_values(array_filter(array_map(static fn ($o) => trim((string) $o), is_array($change['options'] ?? null) ? $change['options'] : [])));

                if (!isset($fields[$key]) || !in_array($fields[$key]['type'], ['select', 'multiselect'], true)) {
                    throw new InvalidArgumentException("{$key} is not a visible one-of / several-of field");
                }
                $before = $fields[$key]['options'] ?? [];
                $fields[$key]['options'] = $adding
                    ? array_values(array_unique([...$before, ...$options]))
                    : array_values(array_filter($before, static fn ($o) => !in_array(mb_strtolower($o), array_map('mb_strtolower', $options), true)));
                $changes[] = ($adding ? 'Add options to ' : 'Remove options from ') . $fields[$key]['label'] . ': ' . implode(', ', $options);
                $marks[$key] ??= 'options';
            }
        }

        $added = self::fieldInput($args['add_fields'] ?? []);

        foreach ($added as $f) {
            $changes[] = 'Add field ' . $f['label'];
        }

        $input = [];

        try {
            $schema = TransactionFields::normalizeSchema([...array_values($fields), ...$added], $current);
        } catch (ValidationException $e) {
            throw new InvalidArgumentException(self::errors($e));
        }
        $existingKeys = array_keys($byKey);

        foreach ($schema as $f) {
            if (!in_array($f['key'], $existingKeys, true)) {
                $marks[$f['key']] = 'new';
            }
        }

        if ($added !== [] || $hide !== [] || $restore !== [] || $marks !== []) {
            $input['fields'] = array_values(array_filter($schema, static fn ($f) => !($f['hidden'] ?? false)));
        }

        if (isset($args['name']) && trim((string) $args['name']) !== '' && trim((string) $args['name']) !== $type->name) {
            $name = mb_substr(trim((string) $args['name']), 0, 64);
            $taken = $user->transactionTypes()->whereKeyNot($type->id)->pluck('name')->contains(fn ($n) => mb_strtolower((string) $n) === mb_strtolower($name));

            if ($taken) {
                throw new InvalidArgumentException("you already have a type called {$name}");
            }
            $changes[] = "Rename type {$type->name} → {$name}";
            $input['name'] = $name;
        }

        if (array_key_exists('emoji', $args) && self::emoji($args['emoji']) !== $type->emoji) {
            $input['emoji'] = self::emoji($args['emoji']);
            $changes[] = 'Emoji ' . ($input['emoji'] ?? 'none');
        }

        if (isset($args['color']) && self::color($args['color'])) {
            $input['color'] = self::color($args['color']);
            $changes[] = 'Color ' . $input['color'];
        }
        $notes = [];

        if (array_key_exists('default_tags', $args)) {
            [$tagIds, $missing] = $this->tagIds($user, $args['default_tags'] ?? []);
            $input['default_tag_ids'] = $tagIds;
            $changes[] = 'Default tags: ' . ($tagIds === [] ? 'none' : implode(' · ', $this->tagLabels($user, $tagIds)));

            if ($missing !== []) {
                $notes[] = 'Tag not found, skipped: ' . implode(', ', $missing);
            }
        }

        if ($input === []) {
            throw new InvalidArgumentException('nothing to change');
        }

        $preview = [
            'name' => $input['name'] ?? $type->name,
            'emoji' => array_key_exists('emoji', $input) ? $input['emoji'] : $type->emoji,
            'color' => $input['color'] ?? $type->color,
            'fields' => $schema,
            'default_tag_ids' => $input['default_tag_ids'] ?? $type->defaultTagIds(),
        ];
        $card = [
            'type' => 'transaction_types',
            'mode' => 'update',
            'title' => 'Edit type',
            'rows' => [$this->cardRow($user, $preview, $marks, $changes, $notes)],
        ];

        return ['payload' => ['id' => (int) $type->id, 'input' => $input], 'preview' => self::cardText($card), 'card' => $card];
    }

    /**
     * @param array<string, mixed> $args
     */
    private function prepareArchive(User $user, array $args): array
    {
        $type = $this->findType($user, $args['type'] ?? null);
        $count = $type->transactions()->count();
        $card = [
            'type' => 'transaction_types',
            'mode' => 'archive',
            'title' => 'Archive type',
            'rows' => [$this->cardRow($user, $type->only(['name', 'emoji', 'color', 'fields', 'default_tag_ids']), notes: [
                $count . ' transaction' . ($count === 1 ? '' : 's') . ' keep the type and values; restore it any time in Manage Types.',
            ])],
        ];

        return ['payload' => ['id' => (int) $type->id], 'preview' => self::cardText($card), 'card' => $card];
    }

    /** @param array<string, mixed> $payload */
    private function applyCreate(User $user, array $payload): array
    {
        $types = DB::transaction(fn () => array_map(
            fn (array $input) => $this->save->execute($user, $input),
            array_values(array_filter($payload['types'] ?? [], 'is_array')),
        ));
        $labels = implode(', ', array_map(static fn (TransactionType $t) => $t->label(), $types));

        return [
            'ok' => true,
            'message' => (count($types) === 1 ? '✅ Type created: ' : '✅ Types created: ') . $labels,
            'summary' => 'Transaction types created via agent confirm: ' . $labels,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function applyUpdate(User $user, array $payload): array
    {
        $type = $user->transactionTypes()->find((int) ($payload['id'] ?? 0));

        if (!$type) {
            return ['ok' => false, 'message' => 'That type no longer exists.', 'summary' => 'Type to change no longer exists.'];
        }
        $type = $this->save->execute($user, is_array($payload['input'] ?? null) ? $payload['input'] : [], $type);

        return ['ok' => true, 'message' => '✅ Type updated: ' . $type->label(), 'summary' => 'Transaction type updated via agent confirm.'];
    }

    /** @param array<string, mixed> $payload */
    private function applyArchive(User $user, array $payload): array
    {
        $type = $user->transactionTypes()->find((int) ($payload['id'] ?? 0));

        if (!$type) {
            return ['ok' => false, 'message' => 'That type is already archived or gone.', 'summary' => 'Type to archive no longer exists.'];
        }
        $type->delete();

        return ['ok' => true, 'message' => '🗄 Type archived: ' . $type->label(), 'summary' => 'Transaction type archived via agent confirm.'];
    }

    // ---------------------------------------------------------------------

    private function findType(User $user, mixed $ref): TransactionType
    {
        $ref = trim((string) $ref);

        if ($ref === '') {
            throw new InvalidArgumentException('type is required');
        }
        $types = $user->transactionTypes()->get();
        $type = is_numeric($ref)
            ? $types->firstWhere('id', (int) $ref)
            : $types->first(fn (TransactionType $t) => mb_strtolower($t->name) === mb_strtolower($ref) || mb_strtolower($t->label()) === mb_strtolower($ref));

        if (!$type) {
            throw new InvalidArgumentException("unknown type \"{$ref}\"; types: " . $types->pluck('name')->implode(', '));
        }

        return $type;
    }

    /**
     * Model field args → editor payload (only known keys).
     *
     * @return list<array<string, mixed>>
     */
    private static function fieldInput(mixed $raw): array
    {
        $out = [];

        foreach (is_array($raw) ? $raw : [] as $f) {
            if (!is_array($f)) {
                continue;
            }
            $out[] = array_intersect_key($f, array_flip(['key', 'label', 'type', 'unit', 'options', 'formula', 'fill_amount', 'required', 'min', 'max']));
        }

        return $out;
    }

    /**
     * @return array{0: list<int>, 1: list<string>} [owned tag ids, titles not found]
     */
    private function tagIds(User $user, mixed $titles): array
    {
        $byTitle = $user->tags()->pluck('id', 'title')->mapWithKeys(fn ($id, $title) => [mb_strtolower(trim((string) $title)) => (int) $id]);
        $ids = [];
        $missing = [];

        foreach (is_array($titles) ? array_slice($titles, 0, 10) : [] as $title) {
            $clean = trim(preg_replace('/^\p{Extended_Pictographic}\s*/u', '', trim((string) $title)) ?? '');
            $id = $byTitle[mb_strtolower($clean)] ?? $byTitle[mb_strtolower(trim((string) $title))] ?? null;

            if ($id) {
                $ids[] = $id;
            } elseif ($clean !== '') {
                $missing[] = $clean;
            }
        }

        return [array_values(array_unique($ids)), $missing];
    }

    /**
     * @param list<int> $ids
     * @return list<string>
     */
    private function tagLabels(User $user, array $ids): array
    {
        return $user->tags()->whereIn('id', $ids)->get()->map(fn ($t) => trim(($t->emoji ?? '') . ' ' . $t->title))->values()->all();
    }

    /**
     * One type on the card: emoji, name, its fields as "Label · kind detail", default tags.
     *
     * @param array<string, mixed> $type
     * @param array<string, string> $marks key => new|renamed|hidden|restored|options
     * @param list<string> $changes
     * @param list<string> $notes
     * @return array<string, mixed>
     */
    private function cardRow(User $user, array $type, array $marks = [], array $changes = [], array $notes = []): array
    {
        $fields = [];

        foreach (is_array($type['fields'] ?? null) ? $type['fields'] : [] as $f) {
            $mark = $marks[$f['key']] ?? null;

            if (($f['hidden'] ?? false) && $mark !== 'hidden') {
                continue;
            }
            $fields[] = ['key' => $f['key'], 'label' => $f['label'], 'kind' => self::kindText($f), 'mark' => $mark];
        }

        return [
            'emoji' => $type['emoji'] ?? null,
            'name' => $type['name'],
            'color' => $type['color'] ?? null,
            'fields' => $fields,
            'tags' => $this->tagLabels($user, array_map('intval', $type['default_tag_ids'] ?? [])),
            'changes' => $changes,
            'notes' => $notes,
        ];
    }

    private static function emoji(mixed $raw): ?string
    {
        $emoji = mb_substr(trim((string) $raw), 0, 8);

        return $emoji !== '' ? $emoji : null;
    }

    private static function color(mixed $raw): ?string
    {
        $color = trim((string) $raw);

        return preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? strtolower($color) : null;
    }

    private static function errors(ValidationException $e): string
    {
        return collect($e->errors())->flatten()->unique()->implode(' ');
    }
}
