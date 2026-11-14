<?php

namespace App\Support;

use App\Models\Transaction;
use App\Models\TransactionType;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * The one write path for a transaction's type, field values and the amount a computed field fills.
 * Used by Create/UpdateTransactionAction (web, API, MCP) and by the assistant tools.
 *
 * Input keys (all optional): transaction_type_id, data (values by field key), amount,
 * add_options (unknown select values become new options instead of an error).
 *
 * Amount: a given amount always wins (the web form lets people override the computed one). With
 * no amount, a type's fill-amount field supplies it: an expense on create, the old sign on update,
 * and on update only while the old amount still matched the old result (an override stays put).
 */
final class TransactionTypeData
{
    /**
     * @param array<string, mixed> $input
     * @return array{type: ?TransactionType, transaction_type_id: ?int, data: ?array<string, mixed>, amount: ?float, tag_ids: list<int>}
     */
    public static function forCreate(User $user, array $input, bool $requireAll = true): array
    {
        $amount = self::givenAmount($input);
        $typeId = self::typeId($input);

        if ($typeId === null) {
            if ($amount === null) {
                throw ValidationException::withMessages(['amount' => 'Amount is required!']);
            }

            return ['type' => null, 'transaction_type_id' => null, 'data' => null, 'amount' => $amount, 'tag_ids' => []];
        }

        $type = self::ownType($user, $typeId);
        $raw = self::rawData($input);

        if (!empty($input['add_options'])) {
            $type = self::absorbOptions($type, $raw);
        }

        $data = TransactionFields::validate($type, $raw, [], 'data', $requireAll);

        if ($amount === null) {
            $amount = self::filledAmount($type, $data, -1);

            if ($amount === null) {
                $fill = $type->fillAmountField();

                throw ValidationException::withMessages(['amount' => $fill
                    ? "Amount is required! Fill in the fields of {$fill['label']} or type the amount."
                    : 'Amount is required!']);
            }
        }

        return [
            'type' => $type,
            'transaction_type_id' => (int) $type->id,
            'data' => $data === [] ? null : $data,
            'amount' => $amount,
            'tag_ids' => $type->defaultTagIds(),
        ];
    }

    /**
     * Only the keys that change: transaction_type_id, data and amount when touched.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function forUpdate(User $user, Transaction $transaction, array $input, bool $requireAll = true): array
    {
        $typeTouched = array_key_exists('transaction_type_id', $input);
        $dataTouched = array_key_exists('data', $input);

        if (!$typeTouched && !$dataTouched) {
            return [];
        }

        $previous = is_array($transaction->data) ? $transaction->data : [];
        $currentId = $transaction->transaction_type_id !== null ? (int) $transaction->transaction_type_id : null;
        $typeId = $typeTouched ? self::typeId($input) : $currentId;

        if ($typeId === null) {
            // Typeless again: the values stay in data, so picking the type back restores them.
            return ['transaction_type_id' => null];
        }

        $type = self::ownType($user, $typeId, allowArchived: $typeId === $currentId);
        $raw = self::rawData($input);

        if (!empty($input['add_options'])) {
            $type = self::absorbOptions($type, $raw);
        }

        $data = TransactionFields::validate($type, $raw, $previous, 'data', $requireAll);
        $out = ['transaction_type_id' => (int) $type->id, 'data' => $data === [] ? null : $data];

        if (self::givenAmount($input) === null && ($fill = $type->fillAmountField()) !== null) {
            $oldAmount = abs((float) $transaction->amount);
            $oldResult = $typeId === $currentId && is_numeric($previous[$fill['key']] ?? null) ? (float) $previous[$fill['key']] : null;
            $linked = $oldResult === null || abs($oldAmount - $oldResult) < 0.005;
            $amount = $linked ? self::filledAmount($type, $data, (float) $transaction->amount < 0 ? -1 : 1) : null;

            if ($amount !== null) {
                $out['amount'] = $amount;
            }
        }

        return $out;
    }

    /**
     * The amount a fill-amount field gives for these values, signed; null when it has no result.
     *
     * @param array<string, mixed> $data
     */
    public static function filledAmount(TransactionType $type, array $data, int $sign): ?float
    {
        $fill = $type->fillAmountField();
        $value = $fill ? ($data[$fill['key']] ?? null) : null;

        if (!is_numeric($value) || round((float) $value, 2) == 0.0) {
            return null;
        }

        return round(abs((float) $value), 2) * ($sign < 0 ? -1 : 1);
    }

    /**
     * Unknown select / multiselect values become new options (same alphabet, short, ≤60 options).
     *
     * @param array<string, mixed> $raw
     */
    public static function absorbOptions(TransactionType $type, array $raw, bool $persist = true): TransactionType
    {
        $fields = is_array($type->fields) ? $type->fields : [];
        $changed = false;

        foreach ($fields as $i => $field) {
            if (($field['hidden'] ?? false) || !in_array($field['type'] ?? '', ['select', 'multiselect'], true)) {
                continue;
            }
            $value = $raw[$field['key']] ?? null;

            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            $values = $field['type'] === 'select' ? [is_scalar($value) ? (string) $value : ''] : SelectOptions::split($value);

            foreach ($values as $one) {
                $options = $fields[$i]['options'] ?? [];

                if (SelectOptions::match($options, $one) !== null) {
                    continue;
                }
                $new = SelectOptions::newOption($options, $one);

                if ($new !== null) {
                    $fields[$i]['options'][] = $new;
                    $changed = true;
                }
            }
        }

        if ($changed) {
            $type->fields = $fields;

            if ($persist) {
                $type->save();
            }
        }

        return $type;
    }

    public static function ownType(User $user, int $typeId, bool $allowArchived = false): TransactionType
    {
        $query = $user->transactionTypes();

        if ($allowArchived) {
            $query->withTrashed();
        }

        $type = $query->find($typeId);

        if (!$type instanceof TransactionType) {
            throw ValidationException::withMessages(['transaction_type_id' => 'Pick one of your types!']);
        }

        return $type;
    }

    /**
     * @param array<string, mixed> $input
     */
    private static function typeId(array $input): ?int
    {
        $id = $input['transaction_type_id'] ?? null;

        return is_numeric($id) && (int) $id > 0 ? (int) $id : null;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private static function rawData(array $input): array
    {
        return is_array($input['data'] ?? null) ? $input['data'] : [];
    }

    /**
     * @param array<string, mixed> $input
     */
    private static function givenAmount(array $input): ?float
    {
        $amount = $input['amount'] ?? null;

        return is_numeric($amount) && (float) $amount != 0.0 ? round((float) $amount, 2) : null;
    }
}
