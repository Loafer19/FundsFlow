<?php

namespace App\Actions\Budgets;

use App\Channels\Telegram\TelegramClient;
use App\Enums\BudgetType;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\User;
use App\Support\UserFormatter;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class NotifyBudgetThresholdsAction
{
    private const THRESHOLDS = [100, 80];

    public function __construct(
        private readonly TelegramClient $client,
        private readonly CalculateLimitStatusAction $limitStatus,
        private readonly CalculateGoalProgressAction $goalProgress,
    ) {}

    public function execute(): void
    {
        $budgets = Budget::query()
            ->whereHas('currentPeriod')
            ->with(['periods.tags', 'currentPeriod.tags', 'user.identities'])
            ->get();

        $messagesByChat = [];
        $goalsByChat = [];

        foreach ($budgets as $budget) {
            // Goals are not spending caps: no 80%/100% alerts, one note when reached.
            if ($budget->type === BudgetType::Goal) {
                $this->collectReachedGoal($budget, $goalsByChat);

                continue;
            }

            $period = $budget->periods->first(fn (BudgetPeriod $p) => $p->ends_at === null);
            $user = $budget->user;

            if (!$period || (float) $period->amount <= 0 || !$user) {
                continue;
            }

            // Bucket grid (anchor_date) and earlier versions for the active bucket, no extra queries.
            $budget->periods->each(fn (BudgetPeriod $p) => $p->setRelation('budget', $budget));
            $asOf = $user->nowInTimezone();
            $status = $this->limitStatus->execute($period, $user, $asOf);
            $ratio = $status['spent'] / $status['limit'];

            $threshold = collect(self::THRESHOLDS)->first(fn (int $t) => $ratio >= $t / 100);

            if (!$threshold) {
                continue;
            }

            if ($this->alreadySent($budget, $period, $status['start'], $threshold, $asOf)) {
                continue;
            }

            Cache::put(self::cacheKey($budget, $status['start'], $threshold), true, now()->addDays(400));

            $identity = $user->identities->firstWhere('provider', 'telegram');

            if (!$identity || ($identity->meta['muted'] ?? false)) {
                continue;
            }

            $messagesByChat[$identity->external_id][] = sprintf(
                "%s %s: %s / %s (%d%%)\n%s – %s",
                $threshold >= 100 ? '🚨' : '⚠️',
                $budget->label($period),
                UserFormatter::formatMoney($user, $status['spent']),
                UserFormatter::formatMoney($user, $status['limit']),
                $status['percent'],
                UserFormatter::formatDate($user, $status['start']),
                UserFormatter::formatDate($user, $status['end']),
            );
        }

        foreach ($messagesByChat as $chatId => $lines) {
            $this->client->sendMessage($chatId, "💰 Budget alert\n" . implode("\n", $lines));
        }

        foreach ($goalsByChat as $chatId => $lines) {
            $this->client->sendMessage($chatId, "🎯 Savings goal reached\n" . implode("\n", $lines));
        }
    }

    /**
     * One alert per threshold per active bucket of a budget. Keyed by budget (not period
     * version) and the bucket's first day, so editing the amount, length or tags mid-bucket
     * neither re-sends an alert already sent nor resets the bucket.
     */
    public static function cacheKey(Budget $budget, string $bucketStart, int $threshold): string
    {
        return "budget_notified:b{$budget->id}:{$bucketStart}:{$threshold}";
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

        $label = $budget->label($period);

        $goalsByChat[$identity->external_id][] = sprintf(
            '🎉 %s: %s / %s (%d%%)',
            $label,
            UserFormatter::formatMoney($user, $progress['saved']),
            UserFormatter::formatMoney($user, $progress['target']),
            $progress['percent'],
        );
    }

    private function alreadySent(Budget $budget, BudgetPeriod $period, string $bucketStart, int $threshold, Carbon $asOf): bool
    {
        if (Cache::has(self::cacheKey($budget, $bucketStart, $threshold))) {
            return true;
        }

        // Keys written before alerts were keyed per budget: period version id + bucket start
        // clamped to that version's starts_at. Check every version overlapping this bucket.
        [$boundary] = $period->bucketBounds($asOf);
        $boundary = $boundary->toDateString();

        foreach ($budget->periods as $version) {
            if ($version->ends_at !== null && $version->ends_at->toDateString() < $bucketStart) {
                continue;
            }

            $legacyStart = max($boundary, $version->starts_at->toDateString());

            if (Cache::has("budget_notified:{$version->id}:{$legacyStart}:{$threshold}")) {
                return true;
            }
        }

        return false;
    }
}
