<?php

namespace App\Support;

use App\Models\User;

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

        $left = $money((float) $p['remaining']) . ' left';
        $lines[] = in_array($p['status'], ['on_track', 'behind', 'paused'], true)
            ? $left . ' · ' . self::statusLabel($p['status'])
            : $left;

        if ($p['target_date']) {
            $deadline = 'Deadline ' . UserFormatter::formatDate($user, $p['target_date']);

            if ($p['overdue']) {
                $deadline .= ' (passed)';
            } elseif ($p['days_left'] === 0) {
                $deadline .= ' · due today';
            } else {
                $deadline .= ' · ' . $p['days_left'] . ' ' . ($p['days_left'] === 1 ? 'day' : 'days') . ' left';

                if ($p['required_amount'] !== null) {
                    $deadline .= ' · need ~' . $money((float) $p['required_amount']) . '/' . $p['required_per'];
                }
            }
            $lines[] = $deadline;
        }

        if ((float) $p['monthly_pace'] > 0) {
            $pace = 'Pace ~' . $money((float) $p['monthly_pace']) . '/mo';
            $lines[] = $p['projected_date']
                ? $pace . ' · done by ' . UserFormatter::formatDate($user, $p['projected_date'])
                : $pace;
        } else {
            $lines[] = (float) $p['contributed'] > 0 ? 'No contributions in the last 3 months' : 'No contributions yet';
        }

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
