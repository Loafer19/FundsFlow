<?php

namespace App\Actions\Report;

use App\Actions\Agent\AgentPlanningTools;
use App\Actions\Budgets\ListBudgetsAction;
use App\Actions\Transactions\ListTransactionsAction;
use App\Models\BudgetPeriod;
use App\Models\Tag;
use App\Models\Transaction;
use App\Models\User;
use App\Support\PeriodRange;
use App\Support\UserFormatter;
use Illuminate\Support\Collection;

/**
 * Server-side finance report for a period (+ optional tag filter): summary vs
 * the previous period, expenses/income by tag, current budget status and the
 * transaction list, as a self-contained print HTML page (A4) for Gotenberg.
 * Used by the agent (Telegram send_report, web report card download).
 */
class BuildUserReportAction
{
    public const MAX_TRANSACTION_ROWS = 400;

    public function __construct(
        private readonly ListTransactionsAction $listTransactions,
        private readonly ListBudgetsAction $listBudgets,
        private readonly AgentPlanningTools $planning,
    ) {}

    /**
     * Normalized report arguments (owned tags only).
     *
     * @param array<string, mixed> $args period | from/to, tag_ids, untagged
     * @return array{range: PeriodRange, tag_ids: list<int>, untagged: bool}
     */
    public function resolve(User $user, array $args): array
    {
        return [
            'range' => PeriodRange::resolve($user, $args),
            'tag_ids' => $this->planning->ownedTagIds($user, $args['tag_ids'] ?? []),
            'untagged' => filter_var($args['untagged'] ?? false, FILTER_VALIDATE_BOOL),
        ];
    }

    /**
     * @param array<string, mixed> $args
     * @return array{html: string, filename: string, caption: string, summary: array<string, mixed>}
     */
    public function execute(User $user, array $args): array
    {
        ['range' => $range, 'tag_ids' => $tagIds, 'untagged' => $untagged] = $this->resolve($user, $args);
        $money = fn (float $v): string => UserFormatter::formatMoney($user, $v);
        $date = fn ($d): string => UserFormatter::formatDate($user, $d);

        $all = $this->listTransactions->execute($user)->filter(fn (Transaction $tx) => $this->matches($tx, $tagIds, $untagged));
        $current = $this->between($all, $range->from->toDateString(), $range->to->toDateString());
        $previous = $this->between($all, $range->previousFrom->toDateString(), $range->previousTo->toDateString());

        $cur = $this->totals($current);
        $prev = $this->totals($previous);
        $expenseTags = $this->byTag($current, $previous, false);
        $incomeTags = $this->byTag($current, $previous, true);
        $filterLabel = $this->filterLabel($user, $tagIds, $untagged);

        $summary = [
            'title' => 'FundsFlow report',
            'period_label' => $range->label,
            'from' => $range->from->toDateString(),
            'to' => $range->to->toDateString(),
            'previous_label' => $range->previousLabel,
            'previous_from' => $range->previousFrom->toDateString(),
            'previous_to' => $range->previousTo->toDateString(),
            'filter' => $filterLabel,
            'tag_ids' => $tagIds,
            'untagged' => $untagged,
            'income' => $cur['income'],
            'expenses' => $cur['expenses'],
            'net' => $cur['net'],
            'transaction_count' => $cur['count'],
            'previous' => $prev,
            'top_expense_tags' => array_map(fn (array $r) => ['label' => $r['label'], 'amount' => $r['current'], 'previous' => $r['previous']], array_slice($expenseTags, 0, 5)),
        ];

        $e = fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $range_text = $date($range->from) . ' – ' . $date($range->to);
        $delta = function (float $now, float $was) use ($e): string {
            if (abs($was) < 0.005) {
                return '<span class="muted">—</span>';
            }
            $pct = (int) round((abs($now) - abs($was)) / abs($was) * 100);

            return '<span class="' . ($pct > 0 ? 'up' : ($pct < 0 ? 'down' : 'muted')) . '">' . $e(($pct > 0 ? '+' : '') . $pct . '%') . '</span>';
        };

        $card = fn (string $title, float $now, float $was, string $cls) => '<div class="card' . ($title !== 'Expenses' ? ' inc' : '') . '"><div class="k">' . $e($title) . '</div><div class="v ' . $cls . '">'
            . $e($money($now)) . '</div><div class="p">' . $e($range->previousLabel) . ': ' . $e($money($was)) . ' · ' . $delta($now, $was) . '</div></div>';

        $html = '<div class="head"><div><h1>FundsFlow report</h1><div class="sub">' . $e($range->label) . ' · ' . $e($range_text)
            . ($filterLabel !== '' ? ' · ' . $e($filterLabel) : '') . '</div></div><div class="gen">Generated ' . $e($date($range->today)) . '</div></div>';
        $html .= '<div class="cards">'
            . $card('Income', $cur['income'], $prev['income'], 'pos')
            . $card('Expenses', $cur['expenses'], $prev['expenses'], 'neg')
            . $card('Net', $cur['net'], $prev['net'], $cur['net'] < 0 ? 'neg' : 'pos')
            . '<div class="card"><div class="k">Transactions</div><div class="v">' . $cur['count'] . '</div><div class="p">' . $e($range->previousLabel) . ': ' . $prev['count'] . '</div></div>'
            . '</div>';
        if ($range->isPartial()) {
            $html .= '<p class="note">The period is still in progress; the previous period is shown in full.</p>';
        }

        $html .= $this->tagTable('Expenses by tag', $expenseTags, $money, $e, $delta, $range->previousLabel, 'neg');
        if ($incomeTags !== []) {
            $html .= $this->tagTable('Income by tag', $incomeTags, $money, $e, $delta, $range->previousLabel, 'pos');
        }

        $html .= $this->budgetsSection($user, $tagIds, $money, $e);

        $rows = $current->sortByDesc(fn (Transaction $tx) => $tx->at?->format('Y-m-d H:i:s'))->values();
        $html .= '<h2>Transactions</h2>';
        if ($rows->isEmpty()) {
            $html .= '<p class="muted">No transactions in this period.</p>';
        } else {
            $html .= '<table><thead><tr><th>Date</th><th>Note</th><th>Tags</th><th class="r">Amount</th></tr></thead><tbody>';
            foreach ($rows->take(self::MAX_TRANSACTION_ROWS) as $tx) {
                $tags = $tx->tags->map(fn (Tag $t) => trim($t->emoji . ' ' . $t->title))->implode(', ');
                $html .= '<tr><td class="nw">' . $e($date($tx->at)) . '</td><td>' . $e((string) ($tx->note ?? '')) . '</td><td>' . $e($tags)
                    . '</td><td class="r nw ' . ($tx->amount < 0 ? 'neg' : 'pos') . '">' . $e($money((float) $tx->amount)) . '</td></tr>';
            }
            $html .= '</tbody></table>';
            if ($rows->count() > self::MAX_TRANSACTION_ROWS) {
                $html .= '<p class="note">Showing the latest ' . self::MAX_TRANSACTION_ROWS . ' of ' . $rows->count() . ' transactions.</p>';
            }
        }

        $caption = "📄 FundsFlow report · {$range->label}\n" . $range_text
            . ($filterLabel !== '' ? "\n" . $filterLabel : '')
            . "\nIncome: " . $money($cur['income']) . ' · Expenses: ' . $money($cur['expenses']) . ' · Net: ' . $money($cur['net']);

        return [
            'html' => $this->page($html),
            'filename' => 'fundsflow-report-' . $range->from->toDateString() . '_' . $range->to->toDateString() . '.pdf',
            'caption' => mb_substr($caption, 0, 1000),
            'summary' => $summary,
        ];
    }

    /**
     * Same rule as the web tag filter: no filter = all; untagged matches tagless rows; otherwise any selected tag.
     *
     * @param list<int> $tagIds
     */
    private function matches(Transaction $tx, array $tagIds, bool $untagged): bool
    {
        if ($tagIds === [] && !$untagged) {
            return true;
        }
        if ($untagged && $tx->tags->isEmpty()) {
            return true;
        }

        return $tagIds !== [] && $tx->tags->contains(fn (Tag $t) => in_array((int) $t->id, $tagIds, true));
    }

    /**
     * @param iterable<Transaction> $all
     * @return Collection<int, Transaction>
     */
    private function between(iterable $all, string $from, string $to): Collection
    {
        return collect($all)->filter(function (Transaction $tx) use ($from, $to): bool {
            $d = $tx->at?->format('Y-m-d');

            return $d !== null && $d >= $from && $d <= $to;
        })->values();
    }

    /**
     * @param Collection<int, Transaction> $txs
     * @return array{income: float, expenses: float, net: float, count: int}
     */
    private function totals(Collection $txs): array
    {
        $income = round((float) $txs->filter(fn (Transaction $tx) => $tx->amount > 0)->sum('amount'), 2);
        $expenses = round((float) $txs->filter(fn (Transaction $tx) => $tx->amount < 0)->sum('amount'), 2);

        return ['income' => $income, 'expenses' => $expenses, 'net' => round($income + $expenses, 2), 'count' => $txs->count()];
    }

    /**
     * @param Collection<int, Transaction> $current
     * @param Collection<int, Transaction> $previous
     * @return list<array{label: string, current: float, previous: float}>
     */
    private function byTag(Collection $current, Collection $previous, bool $income): array
    {
        $rows = [];
        foreach (['current' => $current, 'previous' => $previous] as $side => $txs) {
            foreach ($txs as $tx) {
                if ($income ? $tx->amount <= 0 : $tx->amount >= 0) {
                    continue;
                }
                $amount = abs((float) $tx->amount);
                $keys = $tx->tags->isEmpty()
                    ? ['untagged' => 'Untagged']
                    : $tx->tags->mapWithKeys(fn (Tag $t) => [$t->id => trim($t->emoji . ' ' . $t->title)])->all();
                foreach ($keys as $key => $label) {
                    $rows[$key] ??= ['label' => $label, 'current' => 0.0, 'previous' => 0.0];
                    $rows[$key][$side] += $amount;
                }
            }
        }

        $rows = array_values(array_filter($rows, fn (array $r) => $r['current'] > 0 || $r['previous'] > 0));
        usort($rows, fn (array $a, array $b) => [$b['current'], $b['previous']] <=> [$a['current'], $a['previous']]);

        return $rows;
    }

    /**
     * @param list<array{label: string, current: float, previous: float}> $rows
     */
    private function tagTable(string $title, array $rows, callable $money, callable $e, callable $delta, string $previousLabel, string $cls): string
    {
        $out = '<h2>' . $e($title) . '</h2>';
        if ($rows === []) {
            return $out . '<p class="muted">Nothing in this period.</p>';
        }
        $out .= $cls === 'pos' ? '<div class="inc">' : '<div>';
        $total = array_sum(array_column($rows, 'current'));
        $max = max(array_column($rows, 'current')) ?: 1;
        $sign = $cls === 'neg' ? -1 : 1;
        $out .= '<table><thead><tr><th>Tag</th><th class="r">Amount</th><th class="r">Share</th><th class="barcol"></th><th class="r">'
            . $e($previousLabel) . '</th><th class="r">Change</th></tr></thead><tbody>';
        foreach (array_slice($rows, 0, 30) as $row) {
            $share = $total > 0 ? round($row['current'] / $total * 100) : 0;
            $out .= '<tr><td>' . $e($row['label']) . '</td><td class="r nw">' . $e($money($sign * $row['current'])) . '</td><td class="r">' . $share . '%</td>'
                . '<td class="barcol"><div class="bar ' . $cls . 'bg" style="width:' . round($row['current'] / $max * 100, 1) . '%"></div></td>'
                . '<td class="r nw muted">' . $e($money($sign * $row['previous'])) . '</td><td class="r">' . $delta($row['current'], $row['previous']) . '</td></tr>';
        }
        $out .= '</tbody></table></div>';

        return $out;
    }

    /**
     * @param list<int> $tagIds
     */
    private function budgetsSection(User $user, array $tagIds, callable $money, callable $e): string
    {
        $now = $user->nowInTimezone();
        $rows = '';
        foreach ($this->listBudgets->execute($user) as $budget) {
            $period = $budget->periods->first(fn (BudgetPeriod $p) => $p->ends_at === null);
            if (!$period) {
                continue;
            }
            if ($tagIds !== [] && $period->tags->pluck('id')->map(fn ($id) => (int) $id)->intersect($tagIds)->isEmpty()) {
                continue;
            }
            $s = $this->planning->budgetPeriodStatus($user, $budget, $period, $now);
            $pct = min(100, max(0, $s['percent']));
            $cls = $s['status'] === 'over' ? 'negbg' : ($s['status'] === 'at_risk' ? 'warnbg' : 'posbg');
            $rows .= '<tr><td>' . $e($s['title']) . '</td><td class="nw">' . $e($s['length']) . '</td><td class="r nw">' . $e($s['spent_formatted']) . ' / ' . $e($s['amount_limit_formatted'])
                . '</td><td class="barcol"><div class="bar ' . $cls . '" style="width:' . $pct . '%"></div></td><td class="r nw">' . $e($s['left_formatted'])
                . '</td><td class="r nw">' . $s['days_left'] . '</td><td class="nw">' . $e(str_replace('_', ' ', $s['status'])) . '</td></tr>';
        }
        if ($rows === '') {
            return '';
        }

        return '<h2>Budgets (current period, as of today)</h2><table><thead><tr><th>Budget</th><th>Length</th><th class="r">Spent / limit</th><th class="barcol"></th><th class="r">Left</th><th class="r">Days left</th><th>Status</th></tr></thead><tbody>'
            . $rows . '</tbody></table>';
    }

    /**
     * @param list<int> $tagIds
     */
    private function filterLabel(User $user, array $tagIds, bool $untagged): string
    {
        if ($tagIds === [] && !$untagged) {
            return '';
        }
        $labels = $user->tags()->whereIn('id', $tagIds)->get()->map(fn (Tag $t) => trim($t->emoji . ' ' . $t->title))->all();
        if ($untagged) {
            $labels[] = 'Untagged';
        }

        return 'Tags: ' . implode(', ', $labels);
    }

    private function page(string $body): string
    {
        return '<!doctype html><html><head><meta charset="utf-8"><title>FundsFlow report</title><style>'
            . '@page{size:A4;margin:14mm 12mm}'
            . 'body{margin:0;font-family:"Inter","Noto Sans","DejaVu Sans",Arial,sans-serif,"Noto Color Emoji";color:#0f172a;font-size:11.5px}'
            . '.head{display:flex;justify-content:space-between;align-items:flex-end;border-bottom:2px solid #6366f1;padding-bottom:8px;margin-bottom:14px}'
            . 'h1{font-size:22px;margin:0}.sub{color:#475569;font-size:12.5px;margin-top:3px}.gen{color:#94a3b8;font-size:10.5px}'
            . 'h2{font-size:14.5px;margin:20px 0 6px;page-break-after:avoid}'
            . '.cards{display:flex;gap:10px}.card{flex:1;border:1px solid #e2e8f0;border-radius:8px;padding:9px 11px}'
            . '.k{color:#64748b;font-size:10.5px;text-transform:uppercase;letter-spacing:.04em}.v{font-size:17px;font-weight:700;margin:3px 0}.p{color:#64748b;font-size:10.5px}'
            . 'table{width:100%;border-collapse:collapse}th{text-align:left;color:#64748b;font-weight:600;font-size:10.5px;border-bottom:1px solid #cbd5e1;padding:4px 6px}'
            . 'td{border-bottom:1px solid #f1f5f9;padding:4px 6px;vertical-align:top}tr{page-break-inside:avoid}'
            . '.r{text-align:right}.nw{white-space:nowrap}.muted{color:#94a3b8}.note{color:#64748b;font-size:10.5px;margin:6px 0 0}'
            . '.pos{color:#059669}.neg{color:#dc2626}.up{color:#dc2626}.down{color:#059669}.inc .up{color:#059669}.inc .down{color:#dc2626}'
            . '.barcol{width:22%}.bar{height:8px;border-radius:4px;min-width:2px}.negbg{background:#f87171}.posbg{background:#34d399}.warnbg{background:#fbbf24}'
            . '</style></head><body>' . $body . '</body></html>';
    }
}
