<?php

namespace App\Actions\Budgets;

use App\Channels\Telegram\TelegramClient;
use App\Enums\BudgetType;
use App\Models\Budget;
use App\Models\User;
use App\Support\UserFormatter;
use Illuminate\Support\Facades\Cache;

class NotifyBudgetThresholdsAction
{
    private const THRESHOLDS = [100, 80];

    public function __construct(
        private readonly TelegramClient $client,
        private readonly CalculateBudgetPeriodSpentAction $calculateSpent,
        private readonly CalculateGoalProgressAction $goalProgress,
    ) {}

    public function execute(): void
    {
        $budgets = Budget::query()
            ->whereHas('currentPeriod')
            ->with(['currentPeriod.tags', 'user.identities'])
            ->get();

        $messagesByChat = [];
        $goalsByChat = [];

        foreach ($budgets as $budget) {
            // Goals are not spending caps: no 80%/100% alerts, one note when reached.
            if ($budget->type === BudgetType::Goal) {
                $this->collectReachedGoal($budget, $goalsByChat);

                continue;
            }

            $period = $budget->currentPeriod;
            $user = $budget->user;

            if (!$period || (float) $period->amount <= 0 || !$user) {
                continue;
            }

            $period->setRelation('budget', $budget); // bucket grid (anchor_date) without a query
            $asOf = $user->nowInTimezone();
            $spent = $this->calculateSpent->execute($period, $user, $asOf);
            $ratio = $spent / (float) $period->amount;

            $threshold = collect(self::THRESHOLDS)->first(fn (int $t) => $ratio >= $t / 100);

            if (!$threshold) {
                continue;
            }

            $bucketStart = $this->calculateSpent->currentBucketStart($period, $asOf)->toDateString();
            $cacheKey = "budget_notified:{$period->id}:{$bucketStart}:{$threshold}";

            if (Cache::has($cacheKey)) {
                continue;
            }

            Cache::put($cacheKey, true, now()->addDays(400));

            $identity = $user->identities->firstWhere('provider', 'telegram');

            if (!$identity || ($identity->meta['muted'] ?? false)) {
                continue;
            }

            $label = $budget->title ?: $period->tags->map(fn ($tag) => $tag->emoji)->implode(' ');
            $emoji = $threshold >= 100 ? '🚨' : '⚠️';

            $messagesByChat[$identity->external_id][] = sprintf(
                '%s %s: %s / %s (%d%%)',
                $emoji,
                $label,
                UserFormatter::formatMoney($user, $spent),
                UserFormatter::formatMoney($user, (float) $period->amount),
                (int) round($ratio * 100),
            );
        }

        foreach ($messagesByChat as $chatId => $lines) {
            $this->client->sendMessage($chatId, "Budget alert\n" . implode("\n", $lines));
        }

        foreach ($goalsByChat as $chatId => $lines) {
            $this->client->sendMessage($chatId, "Savings goal reached\n" . implode("\n", $lines));
        }
    }

    /**
     * Once per goal and target amount (raising the target can notify again).
     *
     * @param array<int|string, list<string>> $goalsByChat
     */
    private function collectReachedGoal(Budget $budget, array &$goalsByChat): void
    {
        $user = $budget->user;
        $period = $budget->currentPeriod;

        if (!$user instanceof User || !$period || (float) $period->amount <= 0) {
            return;
        }

        $progress = $this->goalProgress->execute($budget, $user);

        if (!$progress['completed']) {
            return;
        }

        $cacheKey = 'goal_reached:' . $budget->id . ':' . number_format((float) $period->amount, 2, '.', '');

        if (Cache::has($cacheKey)) {
            return;
        }

        Cache::put($cacheKey, true, now()->addDays(400));

        $identity = $user->identities->firstWhere('provider', 'telegram');

        if (!$identity || ($identity->meta['muted'] ?? false)) {
            return;
        }

        $label = $budget->title ?: $period->tags->map(fn ($tag) => $tag->emoji)->implode(' ');

        $goalsByChat[$identity->external_id][] = sprintf(
            '🎉 %s: %s / %s (%d%%)',
            $label,
            UserFormatter::formatMoney($user, $progress['saved']),
            UserFormatter::formatMoney($user, $progress['target']),
            $progress['percent'],
        );
    }
}
