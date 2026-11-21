<?php

namespace App\Support;

use App\Models\BudgetPeriod;
use App\Models\TransactionType;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Optional non-tag scope for a spending limit period:
 * - null: tag-based limit (budget_period_tag)
 * - ['kind' => 'type', 'type_id' => int]: expenses with transaction_type_id
 * - ['kind' => 'field_sum', 'type_id' => int, 'field' => string]: sum of a numeric field on those expenses
 */
final class BudgetScope
{
    public const KINDS = ['type', 'field_sum'];

    public const FIELD_TYPES = ['number', 'computed'];

    /**
     * Validate and canonicalize an incoming scope array (returns null for tag-scoped limits).
     *
     * @param null|array<string, mixed> $raw
     * @return null|array{kind: string, type_id: int, field?: string}
     */
    public static function normalize(User $user, ?array $raw, ?BudgetPeriod $existing = null): ?array
    {
        if ($raw === null || $raw === []) {
            return null;
        }

        $kind = (string) ($raw['kind'] ?? '');

        if (!in_array($kind, self::KINDS, true)) {
            throw ValidationException::withMessages(['scope.kind' => 'Limit scope must be type or field_sum.']);
        }

        $typeId = is_numeric($raw['type_id'] ?? null) ? (int) $raw['type_id'] : 0;
        $type = $typeId > 0
            ? $user->transactionTypes()->withTrashed()->find($typeId)
            : null;

        $existingTypeId = is_array($existing?->scope) ? (int) ($existing->scope['type_id'] ?? 0) : 0;

        if (!$type || ($type->trashed() && $type->id !== $existingTypeId)) {
            throw ValidationException::withMessages(['scope.type_id' => 'Pick an existing transaction type.']);
        }

        if ($kind === 'type') {
            return [
                'kind' => 'type',
                'type_id' => (int) $type->id,
            ];
        }

        $fieldKey = trim((string) ($raw['field'] ?? ''));

        if ($fieldKey === '' || !JsonPath::keyIsSafe($fieldKey)) {
            throw ValidationException::withMessages(['scope.field' => 'Pick a valid number field.']);
        }

        $existingField = is_array($existing?->scope) ? (string) ($existing->scope['field'] ?? '') : '';
        $matched = self::findNumericField($type, $fieldKey, allowHidden: $type->id === $existingTypeId && $fieldKey === $existingField);

        if ($matched === null) {
            throw ValidationException::withMessages(['scope.field' => 'Pick a number field on that type.']);
        }

        return [
            'kind' => 'field_sum',
            'type_id' => (int) $type->id,
            'field' => $fieldKey,
        ];
    }

    /**
     * @param null|array<string, mixed> $a
     * @param null|array<string, mixed> $b
     */
    public static function same(?array $a, ?array $b): bool
    {
        if ($a === null || $a === []) {
            return $b === null || $b === [];
        }

        if ($b === null || $b === []) {
            return false;
        }

        return ($a['kind'] ?? null) === ($b['kind'] ?? null)
            && (int) ($a['type_id'] ?? 0) === (int) ($b['type_id'] ?? 0)
            && ($a['field'] ?? null) === ($b['field'] ?? null);
    }

    /**
     * Human label for a scoped period, e.g. "🍽 Restaurant" or "🍽 Restaurant · Tip".
     *
     * @param null|array<string, mixed> $scope
     */
    public static function label(?array $scope, ?TransactionType $type = null): string
    {
        if (!is_array($scope) || empty($scope['type_id'])) {
            return '';
        }

        $type ??= TransactionType::withTrashed()->find((int) $scope['type_id']);

        if (!$type) {
            return 'Type #' . (int) $scope['type_id'];
        }

        $base = $type->label();

        if (($scope['kind'] ?? null) === 'field_sum' && !empty($scope['field'])) {
            $fieldKey = (string) $scope['field'];
            $fieldLabel = self::fieldLabel($type, $fieldKey);

            return $base . ' · ' . $fieldLabel;
        }

        return $base;
    }

    public static function fieldLabel(TransactionType $type, string $key): string
    {
        foreach (is_array($type->fields) ? $type->fields : [] as $field) {
            if (is_array($field) && ($field['key'] ?? null) === $key) {
                return (string) ($field['label'] ?? $key);
            }
        }

        return $key;
    }

    /**
     * @return null|array<string, mixed>
     */
    private static function findNumericField(TransactionType $type, string $key, bool $allowHidden): ?array
    {
        $fields = $allowHidden ? (is_array($type->fields) ? $type->fields : []) : $type->visibleFields();

        foreach ($fields as $field) {
            if (!is_array($field) || ($field['key'] ?? null) !== $key) {
                continue;
            }

            if (in_array($field['type'] ?? '', self::FIELD_TYPES, true)) {
                return $field;
            }
        }

        return null;
    }
}
