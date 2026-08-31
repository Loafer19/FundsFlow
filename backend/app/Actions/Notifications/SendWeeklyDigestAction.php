<?php

namespace App\Actions\Notifications;

use App\Channels\Telegram\TelegramClient;
use App\Models\Transaction;
use App\Models\User;
use App\Support\UserFormatter;
use Carbon\Carbon;

class SendWeeklyDigestAction
{
    public function __construct(private readonly TelegramClient $client) {}

    public function execute(): void
    {
        $start = now()->subWeek()->startOfWeek();
        $end = now()->subWeek()->endOfWeek();
        $previousStart = now()->subWeeks(2)->startOfWeek();
        $previousEnd = now()->subWeeks(2)->endOfWeek();

        $users = User::query()->has('transactions')->with('identities')->get();

        foreach ($users as $user) {
            $identity = $user->identities->firstWhere('provider', 'telegram');

            if (!$identity || ($identity->meta['muted'] ?? false)) {
                continue;
            }

            $current = $this->summarize($user, $start, $end);

            if ($current['count'] === 0) {
                continue;
            }

            $previous = $this->summarize($user, $previousStart, $previousEnd);

            $this->client->sendMessage($identity->external_id, $this->formatMessage($user, $start, $end, $current, $previous));
        }
    }

    /**
     * @return array{count: int, income: float, expenses: float, top: ?array{tag: object, amount: float}}
     */
    private function summarize(User $user, Carbon $start, Carbon $end): array
    {
        $transactions = Transaction::query()
            ->where('user_id', $user->id)
            ->whereBetween('at', [$start, $end])
            ->with('tags')
            ->get();

        $income = (float) $transactions->where('amount', '>', 0)->sum('amount');
        $expenses = (float) $transactions->where('amount', '<', 0)->sum('amount') * -1;

        $byTag = [];

        foreach ($transactions->where('amount', '<', 0) as $transaction) {
            foreach ($transaction->tags as $tag) {
                $byTag[$tag->id] ??= ['tag' => $tag, 'amount' => 0.0];
                $byTag[$tag->id]['amount'] += abs((float) $transaction->amount);
            }
        }

        return [
            'count' => $transactions->count(),
            'income' => $income,
            'expenses' => $expenses,
            'top' => collect($byTag)->sortByDesc('amount')->first(),
        ];
    }

    /**
     * @param array{count: int, income: float, expenses: float, top: ?array{tag: object, amount: float}} $current
     * @param array{count: int, income: float, expenses: float, top: ?array{tag: object, amount: float}} $previous
     */
    private function formatMessage(User $user, Carbon $start, Carbon $end, array $current, array $previous): string
    {
        $net = $current['income'] - $current['expenses'];
        $netFormatted = UserFormatter::formatMoney($user, abs($net));
        $netSigned = ($net >= 0 ? '+' : '-') . $netFormatted;

        $lines = [
            sprintf(
                '📊 Weekly report (%s–%s)',
                UserFormatter::formatDate($user, $start),
                UserFormatter::formatDate($user, $end),
            ),
            "{$current['count']} transactions",
            sprintf(
                'Income: +%s · Expenses: -%s',
                UserFormatter::formatMoney($user, $current['income']),
                UserFormatter::formatMoney($user, $current['expenses']),
            ),
            "Net: {$netSigned}",
        ];

        if ($current['top']) {
            $tag = $current['top']['tag'];
            $lines[] = sprintf(
                'Top category: %s %s (-%s)',
                $tag->emoji,
                $tag->title,
                UserFormatter::formatMoney($user, $current['top']['amount']),
            );
        }

        if ($previous['expenses'] > 0) {
            $diff = (($current['expenses'] - $previous['expenses']) / $previous['expenses']) * 100;
            $lines[] = sprintf('Expenses vs last week: %+.0f%%', $diff);
        }

        return implode("\n", $lines);
    }
}
