<?php

namespace App\Support;

use App\Models\User;
use Carbon\Carbon;

/**
 * Plain-text lines describing savings goal progress (Telegram card, PDF report).
 * Input is the array from CalculateGoalProgressAction::execute().
 */
final class GoalSummary
{
    /**
     * @param array<string, mixed> $p
     * @return list<string>
     */
    public static function lines(User $user, array $p): array
    {
        $money = fn (float $v): string => UserFormatter::formatMoney($user, $v);
        $lines = ['Saved ' . $money((float) $p['saved']) . ' of ' . $money((float) $p['target'])];

        if ($p['completed']) {
            $lines[] = '✅ Completed';

            return $lines;
        }

        $lines[] = $money((float) $p['remaining']) . ' left';

        if ($p['target_date']) {
            $deadline = 'Deadline ' . UserFormatter::formatDate($user, $p['target_date']);

            if ($p['overdue']) {
                $deadline .= ' (passed)';
            } else {
                $deadline .= ' · ' . $p['days_left'] . ' ' . ($p['days_left'] === 1 ? 'day' : 'days') . ' left';
            }

            if ($p['required_amount'] !== null) {
                $deadline .= ' · need ~' . $money((float) $p['required_amount']) . '/' . $p['required_per'];
            }
            $lines[] = $deadline;
        }

        $lines[] = $p['projected_date']
            ? 'At current pace: ' . Carbon::parse($p['projected_date'])->format('F Y')
            : 'No contributions yet to project a date';

        if (!empty($p['started_at']) && $p['started_at'] < $user->todayDateString()) {
            $lines[] = 'Counting since ' . UserFormatter::formatDate($user, $p['started_at']);
        }

        return $lines;
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'completed' => 'completed',
            'on_track' => 'on track',
            'behind' => 'behind',
            'paused' => 'paused',
            default => 'in progress',
        };
    }
}
