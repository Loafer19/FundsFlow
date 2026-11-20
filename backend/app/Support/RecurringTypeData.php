<?php

namespace App\Support;

use App\Models\RecurringTransaction;
use App\Models\TransactionType;
use App\Models\User;

/**
 * Type + field template on a recurring rule: same ownership and field schema as transactions,
 * then Period / Next charge are stamped from the rule's frequency and next_run_at when the
 * type has those field keys (starter Subscription, or a renamed / custom type with the same keys).
 */
final class RecurringTypeData
{
    public const PERIOD_MONTHLY = 'Monthly';

    public const PERIOD_YEARLY = 'Yearly';

    /**
     * @param  array<string, mixed>  $input
     * @return array{transaction_type_id: ?int, data: ?array<string, mixed>}
     */
    public static function forWrite(User $user, array $input, string $frequency, string $nextRunAt, bool $allowArchived = false, bool $requireAll = true): array
    {
        $typeId = TransactionTypeData::typeId($input);

        if ($typeId === null) {
            return ['transaction_type_id' => null, 'data' => null];
        }

        $type = TransactionTypeData::ownType($user, $typeId, $allowArchived);
        $raw = is_array($input['data'] ?? null) ? $input['data'] : [];

        if (!empty($input['add_options'])) {
            $type = TransactionTypeData::absorbOptions($type, $raw);
        }

        $data = TransactionFields::validate($type, $raw, [], 'data', $requireAll);
        $data = self::applyScheduleFields($type, $data, $frequency, $nextRunAt);

        return [
            'transaction_type_id' => (int) $type->id,
            'data' => $data,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function applyScheduleFields(TransactionType $type, array $data, string $frequency, string $nextRunAt): array
    {
        if ($type->hasFieldKey('period')) {
            $auto = self::periodFromFrequency($frequency);
            if ($auto !== null) {
                $data['period'] = $auto;
            }
        }

        if ($type->hasFieldKey('next_charge') && $nextRunAt !== '') {
            $data['next_charge'] = $nextRunAt;
        }

        return $data;
    }

    public static function periodFromFrequency(string $frequency): ?string
    {
        return match ($frequency) {
            'monthly' => self::PERIOD_MONTHLY,
            'yearly' => self::PERIOD_YEARLY,
            default => null,
        };
    }

    public static function typeLine(?RecurringTransaction $rule): ?string
    {
        if (!$rule?->transaction_type_id) {
            return null;
        }

        $rule->loadMissing('transactionType');
        $type = $rule->transactionType;
        if (!$type) {
            return null;
        }

        $label = trim($type->emoji . ' ' . $type->name);
        $service = trim((string) (($rule->data ?? [])['service'] ?? ''));

        return $service !== '' ? $label . ' · ' . $service : $label;
    }
}
