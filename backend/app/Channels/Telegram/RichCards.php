<?php

namespace App\Channels\Telegram;

use App\Models\Transaction;
use App\Models\User;
use App\Support\TagTree;
use App\Support\UserFormatter;

/**
 * Bot API rich-message HTML (h3/h4, tables, footer) for the cards that are widgets when Telegram
 * allows them: widget -> rich (these) -> classic HTML. Every builder returns ['rich', 'html'] or
 * just the rich string when the caller already has its classic text.
 */
class RichCards
{
    public static function esc(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Two-column table; values are already HTML.
     *
     * @param list<array{0: string, 1: string}> $rows
     */
    public static function table(array $rows): string
    {
        if ($rows === []) {
            return '';
        }

        $html = '';

        foreach ($rows as [$label, $value]) {
            $html .= '<tr><td>' . $label . '</td><td>' . $value . '</td></tr>';
        }

        return '<table striped compact>' . $html . '</table>';
    }

    public static function bar(float $ratio, int $width = 10): string
    {
        $filled = (int) floor(round(max(0.0, min(1.0, $ratio)) * $width, 6));

        return str_repeat('█', $filled) . str_repeat('░', $width - $filled);
    }

    /**
     * This month's income, expenses, net and top expense tags (/month and the pinned summary).
     *
     * @return array{rich: string, html: string}
     */
    public static function monthSummary(User $user, bool $pinned = false): array
    {
        $now = $user->nowInTimezone();
        $transactions = $user->transactions()
            ->with('tags')
            ->onDays($now->copy()->startOfMonth()->startOfDay(), $now->copy()->endOfMonth()->startOfDay())
            ->get();

        $income = (float) $transactions->filter(fn (Transaction $t) => $t->amount > 0)->sum('amount');
        $expense = (float) $transactions->filter(fn (Transaction $t) => $t->amount < 0)->sum('amount');
        $net = $income + $expense;
        $money = fn (float $v): string => self::esc(UserFormatter::formatMoney($user, $v));
        $signed = fn (float $v): string => ($v > 0 ? '+' : '') . $money($v);

        // Top-level tags, each including its child tags; a transaction counts once per top-level tag.
        $tree = TagTree::for($user);
        $byTag = [];

        foreach ($transactions->filter(fn (Transaction $t) => $t->amount < 0) as $transaction) {
            foreach ($tree->rootsOf($transaction->tags->pluck('id')) as $id) {
                $byTag[$id] ??= ['label' => $tree->label($id), 'amount' => 0.0];
                $byTag[$id]['amount'] += abs((float) $transaction->amount);
            }
        }

        $top = collect($byTag)->sortByDesc('amount')->take(3)->values();
        $month = self::esc($now->format('m.Y'));

        $rich = '<h3>📊 ' . $month . '</h3>' . self::table([
            ['Income', '<b>' . $signed($income) . '</b>'],
            ['Expenses', '<b>' . $money($expense) . '</b>'],
            ['Net', '<b>' . $signed($net) . '</b>'],
        ]);

        $lines = [
            '📊 <b>' . $month . '</b>',
            'Income: ' . $signed($income),
            'Expenses: ' . $money($expense),
            'Net: ' . $signed($net),
        ];

        if ($top->isNotEmpty()) {
            $max = max(1.0, (float) $top->first()['amount']);
            $rich .= '<h4>Top expense tags</h4>' . self::table($top->map(fn (array $row): array => [
                self::esc($row['label']),
                '<code>' . self::bar($row['amount'] / $max, 8) . '</code> ' . $money(-$row['amount']),
            ])->all());
            $lines[] = '';
            $lines[] = '<b>Top expense tags</b>';

            foreach ($top as $i => $row) {
                $lines[] = ($i + 1) . ') ' . self::esc($row['label']) . ' — ' . $money(-$row['amount']);
            }
        }

        $count = $transactions->count();
        $footer = $count . ' transaction' . ($count === 1 ? '' : 's');

        if ($pinned) {
            // A pinned text card is edited in place; the time shows it is live.
            $footer .= ' · updated ' . $now->format('d.m H:i');
        }

        $rich .= '<footer>' . self::esc($footer) . '</footer>';
        $lines[] = '';
        $lines[] = '<i>' . self::esc($footer) . '</i>';

        return ['rich' => $rich, 'html' => implode("\n", $lines)];
    }

    /**
     * Budget alert / weekly digest from the report.html payload (h, s, k, l, g).
     *
     * @param array<string, mixed> $report
     */
    public static function report(array $report): string
    {
        $rich = '<h4>' . self::esc((string) ($report['h'] ?? '')) . '</h4>';

        if (($report['s'] ?? '') !== '') {
            $rich .= '<p><i>' . self::esc((string) $report['s']) . '</i></p>';
        }

        $rich .= self::table(array_map(
            fn (array $k): array => [self::esc((string) $k[0]), '<b>' . self::esc((string) $k[1]) . '</b>'],
            (array) ($report['k'] ?? []),
        ));

        foreach ((array) ($report['l'] ?? []) as $line) {
            $rich .= '<p>' . self::esc((string) $line) . '</p>';
        }

        foreach ((array) ($report['g'] ?? []) as [$title, $items]) {
            if ($title !== '') {
                $rich .= '<h4>' . self::esc((string) $title) . '</h4>';
            }

            // Items: [label, percent, "spent / limit", tone, status].
            $rows = '';

            foreach ((array) $items as $item) {
                $tone = match ($item[3] ?? '') {
                    'ov' => '🔴 ',
                    'ar' => '🟠 ',
                    'ok' => '🟢 ',
                    default => '',
                };
                $rows .= '<tr><td>' . $tone . '<b>' . self::esc((string) $item[0]) . '</b></td>'
                    . '<td><code>' . self::bar(((int) $item[1]) / 100, 8) . '</code> ' . (int) $item[1] . '%</td>'
                    . '<td>' . self::esc((string) ($item[2] ?? ''))
                    . (($item[4] ?? '') !== '' ? '<br><i>' . self::esc((string) $item[4]) . '</i>' : '') . '</td></tr>';
            }

            if ($rows !== '') {
                $rich .= '<table striped compact>' . $rows . '</table>';
            }
        }

        if (($report['more'] ?? 0) > 0) {
            $rich .= '<footer>+' . (int) $report['more'] . ' more in /budgets</footer>';
        }

        return $rich;
    }
}
