<?php

namespace App\Channels\Telegram\Widgets;

use App\Actions\Budgets\CalculateGoalProgressAction;
use App\Actions\Budgets\CalculateLimitStatusAction;
use App\Actions\Budgets\ListBudgetsAction;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\Identity;
use App\Models\Tag;
use App\Models\Transaction;
use App\Models\User;
use App\Support\TagTree;
use App\Support\UserFormatter;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * chat_data payloads for Telegram HTML widgets (each at most 1024 UTF-8 bytes).
 */
class TelegramWidgetData
{
    public const KIND_SUMMARY = 'summary';

    public const KIND_BUDGETS = 'budgets';

    public const KIND_ADD = 'add';

    public function __construct(
        private readonly ListBudgetsAction $listBudgets,
        private readonly CalculateLimitStatusAction $limitStatus,
        private readonly CalculateGoalProgressAction $goalProgress,
    ) {}

    /**
     * @return array{chat_data: string, caption: string}
     */
    public function summary(User $user): array
    {
        $now = $user->nowInTimezone();
        $from = $now->copy()->startOfMonth()->startOfDay();
        $today = $now->copy()->startOfDay();
        $transactions = $user->transactions()
            ->with('tags')
            ->onDays($from, $today)
            ->get();

        $income = (float) $transactions->filter(fn (Transaction $transaction) => $transaction->amount > 0)->sum('amount');
        $expense = (float) $transactions->filter(fn (Transaction $transaction) => $transaction->amount < 0)->sum('amount');
        $net = $income + $expense;

        $byDay = [];

        foreach ($transactions as $transaction) {
            $day = $transaction->at->toDateString();
            $byDay[$day] = ($byDay[$day] ?? 0.0) + (float) $transaction->amount;
        }

        $spark = [];

        for ($day = $from->copy(); $day->lte($today); $day->addDay()) {
            $spark[] = (int) round($byDay[$day->toDateString()] ?? 0);
        }

        $tagTree = TagTree::for($user);
        $byTag = [];

        foreach ($transactions->filter(fn (Transaction $transaction) => $transaction->amount < 0) as $transaction) {
            foreach ($tagTree->rootsOf($transaction->tags->pluck('id')) as $id) {
                $byTag[$id] ??= ['label' => $tagTree->label($id), 'amount' => 0.0];
                $byTag[$id]['amount'] += abs((float) $transaction->amount);
            }
        }

        $top = collect($byTag)->sortByDesc('amount')->take(3)->values()
            ->map(fn (array $row): array => [
                $this->clip((string) $row['label'], 28),
                $this->money($user, (float) $row['amount']),
            ])
            ->all();

        $period = $now->format('m.Y');
        $fromLabel = $this->date($from);
        $toLabel = $this->date($today);
        $incomeLabel = $this->signedMoney($user, $income);
        $expenseLabel = $this->money($user, abs($expense));
        $netLabel = $this->signedMoney($user, $net);

        $payload = [
            'p' => $period,
            'f' => $fromLabel,
            'to' => $toLabel,
            'i' => $incomeLabel,
            'e' => $expenseLabel,
            'b' => $netLabel,
            'bn' => $net,
            's' => $spark,
            't' => $top,
        ];

        while (strlen($this->json($payload)) > 1024 && $payload['s'] !== []) {
            // Prefer dropping older sparkline points if a long month plus labels overflows.
            array_shift($payload['s']);
        }

        if (strlen($this->json($payload)) > 1024) {
            $payload['t'] = array_slice($payload['t'], 0, 2);
        }

        $caption = $period . "\nIncome: " . $incomeLabel . "\nExpenses: " . $expenseLabel . "\nNet: " . $netLabel;

        return ['chat_data' => $this->json($payload), 'caption' => $caption];
    }

    /**
     * @return array{chat_data: string, caption: string}
     */
    public function budgets(User $user): array
    {
        $items = [];

        foreach ($this->listBudgets->execute($user) as $budget) {
            $item = $this->budgetItem($user, $budget, detailed: true);

            if ($item !== null) {
                $items[] = $item;
            }
        }

        $payload = $this->packBudgetItems($items);
        $lines = ['Limits and savings goals'];

        foreach (array_slice($payload['i'], 0, 6) as $item) {
            $lines[] = ($item['n'] ?? ('#' . $item['id'])) . ' ' . ($item['p'] ?? 0) . '%';
        }

        if (($payload['i'] ?? []) === []) {
            $lines = ['No active budgets or savings goals'];
        }

        return ['chat_data' => $this->json($payload), 'caption' => implode("\n", $lines)];
    }

    /**
     * Full details for one budget, used when they do not fit in chat_data.
     *
     * @return null|array<string, mixed>
     */
    public function budgetDetail(User $user, int $budgetId): ?array
    {
        $budget = $this->listBudgets->execute($user)->first(fn (Budget $item) => (int) $item->id === $budgetId);

        if (!$budget) {
            return null;
        }

        return $this->budgetItem($user, $budget, detailed: true);
    }

    /**
     * @return array{chat_data: string, caption: string}
     */
    public function add(User $user, ?Identity $identity = null): array
    {
        $tags = $this->frequentTags($user, 6);
        $last = null;
        $stored = $identity?->meta['widgets']['add_last'] ?? null;

        if (is_array($stored) && isset($stored['a'])) {
            $last = [
                'a' => (string) $stored['a'],
                'n' => (string) ($stored['n'] ?? ''),
            ];
        }

        $payload = ['tags' => $tags];

        if ($last !== null) {
            $payload['last'] = $last;
        }

        while (strlen($this->json($payload)) > 1024 && $payload['tags'] !== []) {
            array_pop($payload['tags']);
        }

        return ['chat_data' => $this->json($payload), 'caption' => 'Quick add a transaction'];
    }

    /**
     * @param array{a: string, n: string} $last
     */
    public function storeAddLast(Identity $identity, array $last): void
    {
        $meta = $identity->meta ?? [];
        $meta['widgets']['add_last'] = $last;
        $identity->meta = $meta;
        $identity->save();
    }

    /**
     * @return null|array<string, mixed>
     */
    private function budgetItem(User $user, Budget $budget, bool $detailed): ?array
    {
        $period = $budget->periods->first(fn (BudgetPeriod $item) => $item->ends_at === null);

        if (!$period && !$budget->isGoal()) {
            return null;
        }

        $label = $this->clip($budget->label($period), 28);

        if ($budget->isGoal()) {
            $progress = $this->goalProgress->execute($budget, $user);
            $status = match ((string) $progress['status']) {
                'behind', 'overdue' => 'ov',
                'on_track', 'completed' => 'ot',
                default => 'ar',
            };
            $item = [
                'id' => (int) $budget->id,
                'k' => 'g',
                'n' => $label,
                'p' => (int) $progress['percent'],
                's' => $status,
            ];

            if ($detailed) {
                $left = (float) $progress['remaining'];
                $item['sp'] = $this->money($user, (float) $progress['saved']);
                $item['lim'] = $this->money($user, (float) $progress['target']);
                $item['left'] = $progress['completed'] ? 'Completed' : $this->money($user, $left) . ' left';
                $item['f'] = $this->date((string) $progress['started_at']);
                $item['to'] = $progress['target_date'] ? $this->date((string) $progress['target_date']) : '';
                $days = $progress['days_left'];
                $item['days'] = $progress['completed']
                    ? 'Completed'
                    : ($days === null ? '' : ($days === 1 ? '1 day left' : $days . ' days left'));
            }

            return $item;
        }

        $st = $this->limitStatus->execute($period, $user);
        $status = match ((string) $st['status']) {
            'over' => 'ov',
            'at_risk' => 'ar',
            default => 'ot',
        };
        $item = [
            'id' => (int) $budget->id,
            'k' => 'l',
            'n' => $label,
            'p' => (int) $st['percent'],
            's' => $status,
        ];

        if ($detailed) {
            $item['sp'] = $this->money($user, (float) $st['spent']);
            $item['lim'] = $this->money($user, (float) $st['limit']);
            $item['left'] = $st['left'] >= 0
                ? $this->money($user, (float) $st['left']) . ' left'
                : $this->money($user, (float) (-$st['left'])) . ' over';
            $item['f'] = $this->date((string) $st['start']);
            $item['to'] = $this->date((string) $st['end']);
            $item['days'] = $st['days_left'] === 1 ? 'last day' : $st['days_left'] . ' days left';
        }

        return $item;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array{i: list<array<string, mixed>>, more?: int}
     */
    private function packBudgetItems(array $items): array
    {
        $payload = ['i' => $items];

        if (strlen($this->json($payload)) <= 1024) {
            return $payload;
        }

        $compact = array_map(static fn (array $item): array => [
            'id' => $item['id'],
            'k' => $item['k'],
            'n' => $item['n'],
            'p' => $item['p'],
            's' => $item['s'],
        ], $items);
        $payload = ['i' => $compact];
        $dropped = 0;

        while (strlen($this->json($payload)) > 1024 && $compact !== []) {
            array_pop($compact);
            $dropped++;
            $payload = ['i' => $compact, 'more' => $dropped];
        }

        return $payload;
    }

    /**
     * @return list<array{id: int, n: string}>
     */
    private function frequentTags(User $user, int $limit): array
    {
        $counts = [];
        $labels = [];
        $recent = $user->transactions()->with('tags')->latest('at')->limit(120)->get();

        foreach ($recent as $transaction) {
            foreach ($transaction->tags as $tag) {
                $id = (int) $tag->id;
                $counts[$id] = ($counts[$id] ?? 0) + 1;
                $labels[$id] = $this->clip(trim($tag->emoji . ' ' . $tag->title), 22);
            }
        }
        arsort($counts);

        $out = [];

        foreach (array_keys($counts) as $id) {
            if (count($out) >= $limit) {
                break;
            }
            $out[$id] = ['id' => $id, 'n' => $labels[$id]];
        }

        if (count($out) < $limit) {
            $user->tags()
                ->orderBy('title')
                ->limit($limit)
                ->get(['id', 'emoji', 'title'])
                ->each(function (Tag $tag) use (&$out, $limit): void {
                    $id = (int) $tag->id;

                    if (isset($out[$id]) || count($out) >= $limit) {
                        return;
                    }
                    $out[$id] = ['id' => $id, 'n' => $this->clip(trim($tag->emoji . ' ' . $tag->title), 22)];
                });
        }

        return array_values($out);
    }

    private function money(User $user, float $amount): string
    {
        return UserFormatter::formatMoney($user, $amount);
    }

    private function signedMoney(User $user, float $amount): string
    {
        $formatted = $this->money($user, $amount);

        if ($amount > 0 && !str_starts_with($formatted, '+')) {
            return '+' . $formatted;
        }

        return $formatted;
    }

    private function date(CarbonInterface|string $date): string
    {
        $carbon = $date instanceof CarbonInterface ? $date : Carbon::parse($date);

        return $carbon->format('d.m.Y');
    }

    private function clip(string $text, int $max): string
    {
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1) . '…' : $text;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
