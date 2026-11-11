<?php

namespace App\Channels\Telegram\Widgets;

use App\Actions\Budgets\CalculateGoalProgressAction;
use App\Actions\Budgets\CalculateLimitStatusAction;
use App\Actions\Budgets\ListBudgetsAction;
use App\Actions\RecurringTransactions\ListRecurringTransactionsAction;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\Identity;
use App\Models\RecurringTransaction;
use App\Models\Tag;
use App\Models\Transaction;
use App\Models\User;
use App\Support\TagTree;
use App\Support\UserFormatter;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * chat_data payloads for Telegram HTML widgets (each at most 1024 UTF-8 bytes).
 */
class TelegramWidgetData
{
    public const KIND_SUMMARY = 'summary';

    public const KIND_BUDGETS = 'budgets';

    public const KIND_ADD = 'add';

    public const KIND_RECENT = 'recent';

    public const KIND_TAGS = 'tags';

    public const KIND_RECURRING = 'recurring';

    public const KIND_NOTIFICATIONS = 'notifications';

    /** The pinned month summary (summary.html, its own ref). */
    public const KIND_SUMMARY_PIN = 'summary_pin';

    public const KIND_EDIT = 'edit';

    /** Budget alert and weekly digest (one template). */
    public const KIND_REPORT = 'report';

    public const RECENT_PAGE = 8;

    public function __construct(
        private readonly ListBudgetsAction $listBudgets,
        private readonly CalculateLimitStatusAction $limitStatus,
        private readonly CalculateGoalProgressAction $goalProgress,
        private readonly ListRecurringTransactionsAction $listRecurring,
    ) {}

    /**
     * @return array{chat_data: string}
     */
    public function summary(User $user): array
    {
        $now = $user->nowInTimezone();
        $from = $now->copy()->startOfMonth()->startOfDay();
        $to = $now->copy()->endOfMonth()->startOfDay();
        $transactions = $user->transactions()
            ->with('tags')
            ->onDays($from, $to)
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

        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
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
        $toLabel = $this->date($to);
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

        return ['chat_data' => $this->json($payload)];
    }

    /**
     * @return array{chat_data: string}
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

        return ['chat_data' => $this->json($payload)];
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
     * @return array{chat_data: string}
     */
    public function add(User $user, ?Identity $identity = null): array
    {
        $expense = $this->frequentTags($user, 5, -1);
        $income = $this->frequentTags($user, 5, 1);
        $tags = $this->frequentTags($user, 6, null);
        $last = null;
        $stored = $identity?->meta['widgets']['add_last'] ?? null;

        if (is_array($stored) && isset($stored['a'])) {
            $last = [
                'a' => (string) $stored['a'],
                'n' => (string) ($stored['n'] ?? ''),
            ];
        }

        $payload = [];

        if ($expense !== [] || $income !== []) {
            $payload['e'] = $expense;
            $payload['i'] = $income;
        }

        $payload['tags'] = $tags;

        if ($last !== null) {
            $payload['last'] = $last;
        }

        while (strlen($this->json($payload)) > 1024) {
            if (isset($payload['e']) || isset($payload['i'])) {
                unset($payload['e'], $payload['i']);

                continue;
            }

            if (($payload['tags'] ?? []) === []) {
                break;
            }

            array_pop($payload['tags']);
        }

        return ['chat_data' => $this->json($payload)];
    }

    /**
     * @return array{chat_data: string}
     */
    public function recent(User $user, int $offset = 0): array
    {
        return ['chat_data' => $this->json($this->recentPage($user, $offset))];
    }

    /**
     * @return array{i: list<array<string, mixed>>, more?: int}
     */
    public function recentPage(User $user, int $offset = 0, int $limit = self::RECENT_PAGE): array
    {
        $offset = max(0, $offset);
        $limit = max(1, min(12, $limit));
        $rows = $user->transactions()
            ->with(['tags', 'attachments'])
            ->latest('at')
            ->offset($offset)
            ->limit($limit + 1)
            ->get();
        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit);
        $items = $rows->map(fn (Transaction $transaction) => $this->recentItem($user, $transaction))->all();

        return $this->packRecentItems($items, $hasMore);
    }

    /**
     * This month's expenses and income per top-level tag (a parent includes its children), every
     * top-level tag listed so the widget doubles as the tag list.
     * Rows: [id, label, expenses "-X" or "", income "+Y" or "", expense share %, income share %, has children].
     *
     * @return array{chat_data: string}
     */
    public function tags(User $user): array
    {
        [$from, $to] = $this->monthRange($user);
        $tree = TagTree::for($user);
        $sums = [];
        $untagged = [0.0, 0.0];
        $totals = [0.0, 0.0];

        foreach ($this->monthTransactions($user, $from, $to) as $transaction) {
            $amount = (float) $transaction->amount;
            $side = $amount < 0 ? 0 : 1;
            $totals[$side] += abs($amount);
            $ids = $transaction->tags->pluck('id');

            if ($ids->isEmpty()) {
                $untagged[$side] += abs($amount);

                continue;
            }

            foreach ($tree->rootsOf($ids) as $root) {
                $sums[$root] ??= [0.0, 0.0];
                $sums[$root][$side] += abs($amount);
            }
        }

        $rows = [];

        foreach ($user->tags()->whereNull('parent_id')->orderBy('title')->get(['id', 'emoji', 'title']) as $tag) {
            $id = (int) $tag->id;
            $rows[] = [$id, $this->clip(trim($tag->emoji . ' ' . $tag->title), 24), $sums[$id] ?? [0.0, 0.0], $tree->childrenOf($id) !== []];
        }

        if ($untagged[0] > 0 || $untagged[1] > 0) {
            $rows[] = [0, 'Untagged', $untagged, false];
        }

        $payload = [
            'p' => $from->format('m.Y'),
            'e' => $totals[0] > 0 ? $this->money($user, -$totals[0]) : '',
            'in' => $totals[1] > 0 ? $this->signedMoney($user, $totals[1]) : '',
            'i' => array_map(
                fn (array $row): array => [$row[0], $row[1], ...$this->flowCells($user, $row[2], $totals), $row[3] ? 1 : 0],
                $this->sortByFlow($rows, 2),
            ),
        ];
        $hidden = 0;

        while (strlen($this->json($payload)) > 1000 && $payload['i'] !== []) {
            array_pop($payload['i']);
            $payload['more'] = ++$hidden;
        }

        return ['chat_data' => $this->json($payload)];
    }

    /**
     * One top-level tag split by its direct children for this month (query answer).
     * Rows: [label, expenses, income, expense share %, income share %].
     *
     * @return null|array<string, mixed>
     */
    public function tagDetail(User $user, int $tagId): ?array
    {
        $tag = $user->tags()->whereKey($tagId)->first(['id', 'emoji', 'title', 'parent_id']);

        if (!$tag instanceof Tag) {
            return null;
        }

        [$from, $to] = $this->monthRange($user);
        $tree = TagTree::for($user);
        $children = $user->tags()->where('parent_id', $tagId)->get(['id', 'emoji', 'title']);
        $subtrees = [];

        foreach ($children as $child) {
            $subtrees[(int) $child->id] = array_flip($tree->expand([(int) $child->id]));
        }

        $own = array_flip($tree->expand([$tagId]));
        $sums = array_fill_keys(array_keys($subtrees), [0.0, 0.0]);
        $direct = [0.0, 0.0];
        $totals = [0.0, 0.0];

        foreach ($this->monthTransactions($user, $from, $to) as $transaction) {
            $ids = $transaction->tags->pluck('id')->map(fn ($id) => (int) $id)->all();

            if (!array_filter($ids, fn (int $id) => isset($own[$id]))) {
                continue;
            }

            $amount = (float) $transaction->amount;
            $side = $amount < 0 ? 0 : 1;
            $totals[$side] += abs($amount);
            $matched = false;

            foreach ($subtrees as $childId => $subtree) {
                if (array_filter($ids, fn (int $id) => isset($subtree[$id]))) {
                    $sums[$childId][$side] += abs($amount);
                    $matched = true;
                }
            }

            if (!$matched) {
                $direct[$side] += abs($amount);
            }
        }

        $rows = [];

        foreach ($children as $child) {
            $rows[] = [$this->clip(trim($child->emoji . ' ' . $child->title), 24), $sums[(int) $child->id]];
        }

        if ($direct[0] > 0 || $direct[1] > 0) {
            $rows[] = ['Without a subtag', $direct];
        }

        $payload = [
            'n' => $this->clip(trim($tag->emoji . ' ' . $tag->title), 28),
            'e' => $totals[0] > 0 ? $this->money($user, -$totals[0]) : '',
            'in' => $totals[1] > 0 ? $this->signedMoney($user, $totals[1]) : '',
            'i' => array_map(
                fn (array $row): array => [$row[0], ...$this->flowCells($user, $row[1], $totals)],
                $this->sortByFlow($rows, 1),
            ),
        ];

        while (strlen($this->json($payload)) > 1000 && $payload['i'] !== []) {
            array_pop($payload['i']);
        }

        return $payload;
    }

    /**
     * Recurring rules, active first and then by next date.
     *
     * @return array{chat_data: string}
     */
    public function recurring(User $user): array
    {
        $rules = $this->listRecurring->execute($user)
            ->sortBy(fn (RecurringTransaction $rule) => [$rule->active ? 0 : 1, $rule->next_run_at->toDateString()])
            ->values();
        $items = $rules->map(fn (RecurringTransaction $rule) => $this->recurringItem($user, $rule))->all();
        $payload = ['i' => $items];
        $hidden = 0;

        while (strlen($this->json($payload)) > 1000 && $payload['i'] !== []) {
            array_pop($payload['i']);
            $payload['more'] = ++$hidden;
        }

        return ['chat_data' => $this->json($payload)];
    }

    /**
     * @return array{id: int, a: string, n: string, fq: string, nx: string, on: int, g?: string}
     */
    public function recurringItem(User $user, RecurringTransaction $rule): array
    {
        $note = trim((string) ($rule->note ?? ''));
        $tags = $rule->relationLoaded('tags') ? $rule->tags : $rule->tags()->get();
        $tag = $tags->first();
        $tagLabel = $tag ? $this->clip(trim($tag->emoji . ' ' . $tag->title), 22) : '';
        $item = [
            'id' => (int) $rule->id,
            'a' => $this->signedMoney($user, (float) $rule->amount),
            'n' => $this->clip($note !== '' ? $note : ($tagLabel !== '' ? $tagLabel : 'No note'), 26),
            'fq' => ucfirst($rule->frequency->value),
            'nx' => $this->date($rule->next_run_at),
            'on' => $rule->active ? 1 : 0,
        ];

        if ($note !== '' && $tagLabel !== '') {
            $item['g'] = $tagLabel;
        }

        if ($rule->ends_at !== null) {
            $item['end'] = $this->date($rule->ends_at);
        }

        return $item;
    }

    /**
     * @return array{chat_data: string}
     */
    public function notifications(Identity $identity): array
    {
        $meta = $identity->meta ?? [];

        return ['chat_data' => $this->json([
            'm' => ($meta['muted'] ?? false) ? 1 : 0,
            'r' => ($meta['remind_off'] ?? false) ? 0 : 1,
            'p' => ($meta['pin'] ?? false) ? 1 : 0,
        ])];
    }

    /**
     * State of the transaction / receipt-draft editor (edit.html). k: t (transaction) or d
     * (draft), a: signed amount, d / td: date and today as Ymd, n: note, s: selected tag ids,
     * e / i: expense and income chips [id, label] (selected ones first), x: closed message.
     *
     * @param list<int> $tagIds
     * @return array{chat_data: string}
     */
    public function editor(User $user, string $kind, float $amount, string $at, ?string $note, array $tagIds, ?string $closed = null): array
    {
        if ($closed !== null) {
            return ['chat_data' => $this->json(['k' => $kind, 'x' => $closed])];
        }

        $tagIds = array_values(array_unique(array_map('intval', $tagIds)));
        $selected = [];

        if ($tagIds !== []) {
            foreach ($user->tags()->whereIn('id', $tagIds)->orderBy('title')->get(['id', 'emoji', 'title']) as $tag) {
                $selected[(int) $tag->id] = [(int) $tag->id, $this->clip(trim($tag->emoji . ' ' . $tag->title), 22)];
            }
        }

        $chips = function (?int $sign) use ($user, $selected): array {
            $list = $this->frequentTags($user, 8, $sign);

            if ($sign !== null && count($list) < 4) {
                $list = [...$list, ...$this->frequentTags($user, 8, null)];
            }
            $out = array_values($selected);
            $seen = array_keys($selected);

            foreach ($list as $tag) {
                $id = (int) $tag['id'];

                if (!in_array($id, $seen, true) && count($out) < 10) {
                    $out[] = [$id, (string) $tag['n']];
                    $seen[] = $id;
                }
            }

            return $out;
        };

        $amount = round($amount, 2);
        $payload = [
            'k' => $kind,
            'a' => ($amount < 0 ? '-' : '') . rtrim(rtrim(number_format(abs($amount), 2, '.', ''), '0'), '.'),
            'd' => str_replace('-', '', substr($at, 0, 10)),
            'td' => str_replace('-', '', $user->todayDateString()),
            'n' => $note !== null && $note !== '' ? $this->clip($note, 48) : '',
            's' => array_keys($selected),
            'e' => $chips(-1),
            'i' => $chips(1),
        ];

        // 1 KB: drop unselected chips from the end, the bigger list first.
        while (strlen($this->json($payload)) > 1000) {
            $side = count($payload['e']) >= count($payload['i']) ? 'e' : 'i';

            if (count($payload[$side]) <= count($selected)) {
                $payload['n'] = $this->clip($payload['n'], 16);

                break;
            }
            array_pop($payload[$side]);
        }

        return ['chat_data' => $this->json($payload)];
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
     * @return array{0: Carbon, 1: Carbon}
     */
    private function monthRange(User $user): array
    {
        $now = $user->nowInTimezone();

        return [$now->copy()->startOfMonth()->startOfDay(), $now->copy()->endOfMonth()->startOfDay()];
    }

    /**
     * @return Collection<int, Transaction>
     */
    private function monthTransactions(User $user, CarbonInterface $from, CarbonInterface $to): Collection
    {
        return $user->transactions()
            ->with('tags')
            ->onDays($from, $to)
            ->get();
    }

    /**
     * Signed amount labels and shares for one tag row: [expenses, income, expense %, income %].
     *
     * @param array{0: float, 1: float} $sums [expenses, income], both positive
     * @param array{0: float, 1: float} $totals
     * @return array{0: string, 1: string, 2: int, 3: int}
     */
    private function flowCells(User $user, array $sums, array $totals): array
    {
        return [
            $sums[0] > 0 ? $this->money($user, -$sums[0]) : '',
            $sums[1] > 0 ? $this->signedMoney($user, $sums[1]) : '',
            $totals[0] > 0 ? (int) round($sums[0] / $totals[0] * 100) : 0,
            $totals[1] > 0 ? (int) round($sums[1] / $totals[1] * 100) : 0,
        ];
    }

    /**
     * Expense rows first (largest first), then income-only rows (largest first), then rows
     * with no transactions this month in their original (alphabetical) order.
     *
     * @param list<array<int, mixed>> $rows
     * @return list<array<int, mixed>>
     */
    private function sortByFlow(array $rows, int $sumsAt): array
    {
        $rank = static fn (array $row): array => [
            $row[$sumsAt][0] > 0 ? 0 : ($row[$sumsAt][1] > 0 ? 1 : 2),
            -($row[$sumsAt][0] > 0 ? $row[$sumsAt][0] : $row[$sumsAt][1]),
        ];
        $indexed = array_map(null, array_keys($rows), $rows);
        usort($indexed, static fn (array $a, array $b): int => [$rank($a[1]), $a[0]] <=> [$rank($b[1]), $b[0]]);

        return array_column($indexed, 1);
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
     * @param list<array<string, mixed>> $items
     * @return array{i: list<array<string, mixed>>, more?: int}
     */
    private function packRecentItems(array $items, bool $hasMore): array
    {
        // Leave room so a widget query answer can wrap this in {"ok":1,...}.
        $max = 1000;
        $payload = ['i' => $items];

        if ($hasMore) {
            $payload['more'] = 1;
        }

        if (strlen($this->json($payload)) <= $max) {
            return $payload;
        }

        // Over the limit: drop long notes first, then show fewer items (down to 5, the rest come with
        // "More"), and only then the full tag lists (gx still says "+N" more tags), so a transaction's
        // detail shows all its tags whenever it can.
        $strip = static fn (array $list, array $keys): array => array_map(static function (array $item) use ($keys): array {
            foreach ($keys as $key) {
                unset($item[$key]);
            }

            return $item;
        }, $list);
        $pack = static fn (array $list, bool $more): array => $more ? ['i' => $list, 'more' => 1] : ['i' => $list];
        $compact = $strip($items, ['fn']);
        $payload = $pack($compact, $hasMore);
        $keep = min(5, count($compact));

        while (strlen($this->json($payload)) > $max && count($compact) > $keep) {
            array_pop($compact);
            $payload = $pack($compact, true);
        }

        if (strlen($this->json($payload)) > $max) {
            $compact = $strip($compact, ['gs']);
            $payload = $pack($compact, $hasMore || count($compact) < count($items));
        }

        while (strlen($this->json($payload)) > $max && $compact !== []) {
            array_pop($compact);
            $payload = $pack($compact, true);
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function recentItem(User $user, Transaction $transaction): array
    {
        $note = trim((string) ($transaction->note ?? ''));
        $tags = $transaction->tags
            ->map(fn (Tag $tag) => $this->clip(trim($tag->emoji . ' ' . $tag->title), 22))
            ->filter()
            ->values()
            ->all();
        $item = [
            'id' => (int) $transaction->id,
            'a' => $this->signedMoney($user, (float) $transaction->amount),
            'n' => $note === '' ? '—' : $this->clip($note, 28),
            'df' => $transaction->at->format('d.m.Y'),
            'g' => $tags[0] ?? 'Untagged',
        ];

        if ($note !== '' && mb_strlen($note) > 28) {
            $item['fn'] = $this->clip($note, 80);
        }

        if (count($tags) > 1) {
            // gx: how many more tags than `g` shows ("💼 Salary +1"); gs: all of them for the detail.
            $item['gx'] = count($tags) - 1;
            $item['gs'] = array_slice($tags, 0, 6);
        }

        if ($transaction->relationLoaded('attachments') && $transaction->attachments->isNotEmpty()) {
            $item['f'] = 1;
        }

        return $item;
    }

    /**
     * @return list<array{id: int, n: string}>
     */
    private function frequentTags(User $user, int $limit, ?int $sign = null): array
    {
        $counts = [];
        $labels = [];
        $recent = $user->transactions()->with('tags')->latest('at')->limit(120)->get();

        foreach ($recent as $transaction) {
            if ($sign !== null) {
                if ($sign < 0 && $transaction->amount >= 0) {
                    continue;
                }

                if ($sign > 0 && $transaction->amount <= 0) {
                    continue;
                }
            }

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

        if ($sign === null && count($out) < $limit) {
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
