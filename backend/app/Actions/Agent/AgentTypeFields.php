<?php

namespace App\Actions\Agent;

use App\Models\Transaction;
use App\Models\TransactionType;
use App\Models\User;
use App\Support\FieldValueParser;
use App\Support\TransactionFields;
use App\Support\TransactionTypeData;
use Illuminate\Validation\ValidationException;

/**
 * Transaction types for the assistant (web + Telegram): the compact schema in the prompt, the
 * `type` / `fields` tool arguments, and turning them into a type id + field values + amount.
 * Lenient like TaleLog's agent path: values that do not fit are dropped (reported back), unknown
 * select values become new options when the change is saved.
 */
class AgentTypeFields
{
    public const MAX_PROMPT_TYPES = 15;

    /** Tool argument schemas shared by log_transaction / create_transaction / update_transaction. */
    public static function toolProperties(): array
    {
        return [
            'type' => ['type' => 'string', 'description' => 'Exact name of one of the user\'s transaction types (see "Transaction types" in the prompt) when the message fits it. Omit otherwise'],
            'fields' => ['type' => 'object', 'description' => 'Values of that type\'s fields by key: number in the field unit (convert units), rating as an integer, duration in minutes, yes/no as boolean, one-of = a listed option (or a short new one in the options\' language), several-of = list, date YYYY-MM-DD. Never pass computed (auto) fields', 'additionalProperties' => true],
        ];
    }

    /**
     * Prompt lines for the user's active types, or '' when they have none.
     */
    public static function promptBlock(User $user): string
    {
        $types = $user->transactionTypes()->orderBy('name')->limit(self::MAX_PROMPT_TYPES)->get();

        if ($types->isEmpty()) {
            return '';
        }

        $tagNames = $user->tags()->pluck('title', 'id');
        $lines = $types->map(function (TransactionType $type) use ($tagNames): string {
            $auto = collect($type->defaultTagIds())->map(fn (int $id) => $tagNames[$id] ?? null)->filter()->implode(', ');

            return '- ' . $type->name . ($type->emoji ? " {$type->emoji}" : '') . ($auto !== '' ? " [auto tags: {$auto}]" : '') . ': ' . TransactionFields::promptSchema($type);
        })->implode("\n");

        return "Transaction types (key*:kind = required; pass type + fields when a message fits one, e.g. \"Fuel: 40 l at 58.9\" → type Fuel, fields {liters: 40, price_per_liter: 58.9}; when a computed field says →amount, omit amount unless the user gave the total; the type's auto tags are added by the app):\n{$lines}\n";
    }

    /**
     * Type + values from tool arguments. `type` = '' / 'none' on update clears the type.
     *
     * @param array<string, mixed> $args
     * @return array{type: ?TransactionType, touched: bool, cleared: bool, fields: array<string, mixed>, dropped: list<string>}
     */
    public function fromArgs(User $user, array $args, ?Transaction $before = null): array
    {
        $name = is_string($args['type'] ?? null) ? trim($args['type']) : null;
        $rawFields = is_array($args['fields'] ?? null) ? $args['fields'] : [];
        $result = ['type' => null, 'touched' => false, 'cleared' => false, 'fields' => [], 'dropped' => []];

        if ($name !== null && in_array(mb_strtolower($name), ['', 'none', 'no type'], true)) {
            return ['cleared' => true, 'touched' => true] + $result;
        }

        $type = $name !== null ? $this->resolveType($user, $name) : null;

        if ($name !== null && $type === null) {
            throw new \InvalidArgumentException("unknown transaction type \"{$name}\"; use one of the listed types or omit type");
        }

        if ($type === null && $rawFields !== []) {
            $type = $before?->transactionType ?? $this->typeFromFields($user, array_keys($rawFields));
        }

        if ($type === null) {
            return $result;
        }

        $mapped = $this->mapFields($type, $rawFields);
        // Unknown select values are judged against a copy here; the real options are added on save.
        $probe = TransactionTypeData::absorbOptions(clone $type, $mapped, persist: false);
        [$values, $dropped] = TransactionFields::validSubset($probe, $mapped);
        $unmapped = array_values(array_diff(array_map('strval', array_keys($rawFields)), array_keys($mapped)));

        return [
            'type' => $type,
            'touched' => true,
            'cleared' => false,
            'fields' => $mapped === [] ? [] : array_intersect_key($mapped, $values),
            'dropped' => [...$dropped, ...$unmapped],
        ];
    }

    /**
     * The values with computed fields evaluated (for cards), validated leniently against $previous.
     *
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $previous
     * @return array<string, mixed>
     */
    public static function evaluate(TransactionType $type, array $fields, array $previous = []): array
    {
        try {
            $probe = TransactionTypeData::absorbOptions(clone $type, $fields, persist: false);

            return TransactionFields::validate($probe, $fields, $previous, requireAll: false);
        } catch (ValidationException) {
            return $previous;
        }
    }

    /** "⛽ Fuel · Liters 40 l · Price per liter 58,90 ₴ · Station OKKO" for cards. */
    public static function cardLine(TransactionType $type, array $data, User $user): string
    {
        $rows = array_map(static fn (array $row): string => $row[0] . ' ' . $row[1], TransactionFields::rows($type, $data, $user));

        return implode(' · ', [$type->label(), ...$rows]);
    }

    /**
     * For list tools: {type: "Fuel", fields: {liters: "40 l", …}}.
     *
     * @return array<string, mixed>
     */
    public static function serialize(Transaction $transaction, User $user): array
    {
        $type = $transaction->transactionType;

        if (!$type instanceof TransactionType) {
            return [];
        }

        $fields = [];

        foreach (TransactionFields::rows($type, is_array($transaction->data) ? $transaction->data : [], $user) as [$label, $value, $key]) {
            $fields[$key] = $value;
        }

        return ['type' => $type->name, 'fields' => $fields];
    }

    private function resolveType(User $user, string $name): ?TransactionType
    {
        $types = $user->transactionTypes()->get();
        $exact = $types->first(fn (TransactionType $type) => mb_strtolower($type->name) === mb_strtolower($name)
            || mb_strtolower($type->label()) === mb_strtolower($name));

        if ($exact) {
            return $exact;
        }

        $hit = FieldValueParser::option($types->pluck('name')->all(), $name);

        return $hit === null ? null : $types->firstWhere('name', $hit);
    }

    /**
     * @param list<int|string> $keys
     */
    private function typeFromFields(User $user, array $keys): ?TransactionType
    {
        $keys = array_map(static fn ($key) => mb_strtolower((string) $key), $keys);
        $hits = $user->transactionTypes()->get()->filter(function (TransactionType $type) use ($keys): bool {
            $known = [];

            foreach ($type->visibleFields() as $field) {
                $known[] = mb_strtolower((string) $field['key']);
                $known[] = mb_strtolower((string) $field['label']);
            }

            return array_diff($keys, $known) === [];
        });

        return $hits->count() === 1 ? $hits->first() : null;
    }

    /**
     * Argument names → field keys (by key or label, case-insensitive). Computed fields are skipped.
     *
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function mapFields(TransactionType $type, array $raw): array
    {
        $byName = [];

        foreach ($type->visibleFields() as $field) {
            if ($field['type'] === 'computed') {
                continue;
            }
            $byName[mb_strtolower((string) $field['key'])] = (string) $field['key'];
            $byName[mb_strtolower((string) $field['label'])] = (string) $field['key'];
        }

        $out = [];

        foreach ($raw as $name => $value) {
            $key = $byName[mb_strtolower(trim((string) $name))] ?? null;

            if ($key !== null && $value !== null && $value !== '') {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
