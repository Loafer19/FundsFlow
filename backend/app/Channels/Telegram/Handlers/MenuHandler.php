<?php

namespace App\Channels\Telegram\Handlers;

use App\Actions\Budgets\CalculateBudgetPeriodSpentAction;
use App\Actions\Budgets\ListBudgetsAction;
use App\Actions\RecurringTransactions\ListRecurringTransactionsAction;
use App\Actions\Tags\CreateTagAction;
use App\Actions\Tags\ListTagsAction;
use App\Actions\Transactions\ListTransactionsAction;
use App\Channels\Telegram\TelegramClient;
use App\Channels\Telegram\TelegramSupport;
use App\Models\BudgetPeriod;
use App\Models\RecurringTransaction;
use App\Models\Tag;
use App\Models\Transaction;
use App\Models\User;
use App\Support\UserFormatter;

class MenuHandler
{
    public function __construct(
        private readonly TelegramClient $client,
        private readonly TelegramSupport $support,
        private readonly ListTagsAction $listTags,
        private readonly ListTransactionsAction $listTransactions,
        private readonly CreateTagAction $createTag,
        private readonly ListBudgetsAction $listBudgets,
        private readonly ListRecurringTransactionsAction $listRecurring,
        private readonly CalculateBudgetPeriodSpentAction $calculateBudgetSpent,
    ) {}

    public function sendMonthSummary(User $user, int|string $chatId): void
    {
        $month = $user->nowInTimezone()->format('Y-m');

        $transactions = $this->listTransactions->execute($user)
            ->filter(fn (Transaction $transaction) => $transaction->at->format('Y-m') === $month);

        $income = (float) $transactions->filter(fn (Transaction $transaction) => $transaction->amount > 0)->sum('amount');
        $expense = (float) $transactions->filter(fn (Transaction $transaction) => $transaction->amount < 0)->sum('amount');

        $monthLabel = $this->support->escapeHtml($user->nowInTimezone()->format('m.Y'));
        $lines = [
            '📊 <b>' . $monthLabel . '</b>',
            'Income: +' . $this->support->escapeHtml(UserFormatter::formatMoney($user, $income)),
            'Expenses: ' . $this->support->escapeHtml(UserFormatter::formatMoney($user, $expense)),
            'Net: ' . $this->support->escapeHtml(UserFormatter::formatMoney($user, $income + $expense)),
        ];

        $byTag = [];

        foreach ($transactions->filter(fn (Transaction $transaction) => $transaction->amount < 0) as $transaction) {
            foreach ($transaction->tags as $tag) {
                $byTag[$tag->id] ??= ['tag' => $tag, 'amount' => 0.0];
                $byTag[$tag->id]['amount'] += abs((float) $transaction->amount);
            }
        }

        $top = collect($byTag)->sortByDesc('amount')->take(3)->values();

        if ($top->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '<b>Top expense tags</b>';

            foreach ($top as $index => $row) {
                /** @var Tag $tag */
                $tag = $row['tag'];
                $lines[] = ($index + 1) . ') ' . $this->support->escapeHtml(trim($tag->emoji . ' ' . $tag->title))
                    . ' — ' . $this->support->escapeHtml(UserFormatter::formatMoney($user, -1 * $row['amount']));
            }
        }

        $this->client->sendMessage($chatId, implode("\n", $lines), null, TelegramSupport::PARSE_HTML);
    }

    public function sendTags(User $user, int|string $chatId): void
    {
        $tags = $this->listTags->execute($user);

        if ($tags->isEmpty()) {
            $this->client->sendMessage($chatId, 'No tags yet');

            return;
        }

        $lines = array_map(
            fn (array $node) => $this->support->formatTagLabel($node['tag'], $node['depth']),
            $this->support->buildTagTree($tags),
        );

        $this->client->sendMessage($chatId, "🏷 Your tags\n" . implode("\n", $lines));
    }

    public function sendRecent(User $user, int|string $chatId): void
    {
        $payload = $this->recentListPayload($user);

        if ($payload === null) {
            $this->client->sendMessage($chatId, 'No transactions yet');

            return;
        }

        $this->client->sendMessage(
            $chatId,
            $payload['text'],
            $payload['reply_markup'],
            TelegramSupport::PARSE_HTML,
        );
    }

    /**
     * @return array{text: string, reply_markup: array<string, mixed>}|null
     */
    public function recentListPayload(User $user): ?array
    {
        $transactions = $this->listTransactions->execute($user)->take(TelegramSupport::RECENT_LIMIT)->values();

        if ($transactions->isEmpty()) {
            return null;
        }

        $items = $transactions->map(
            fn (Transaction $transaction, int $index) => $this->support->formatTransactionListItem($user, $transaction, $index),
        )->all();

        $buttons = $transactions->map(fn (Transaction $transaction, int $index) => [
            'text' => 'Open ' . ($index + 1),
            'callback_data' => "open:{$transaction->id}",
        ])->all();

        return [
            'text' => "🕘 <b>Recent</b>\n\n" . implode("\n\n", $items),
            'reply_markup' => ['inline_keyboard' => array_chunk($buttons, 5)],
        ];
    }

    public function sendBudgets(User $user, int|string $chatId): void
    {
        $budgets = $this->listBudgets->execute($user);
        $blocks = [];

        foreach ($budgets as $budget) {
            $period = $budget->periods->first(fn (BudgetPeriod $period) => $period->ends_at === null);

            if (!$period) {
                continue;
            }

            $label = $budget->title
                ?: $period->tags->map(fn (Tag $tag) => trim($tag->emoji . ' ' . $tag->title))->filter()->implode(', ')
                ?: 'Budget #' . $budget->id;

            $spent = $this->calculateBudgetSpent->execute($period, $user);
            $limit = (float) $period->amount;
            $ratio = $limit > 0 ? ($spent / $limit) : 0.0;
            $pct = (int) round($ratio * 100);

            $bar = $this->support->progressBar($ratio);
            $barLine = $ratio > 1.0 ? $bar : "{$bar} {$pct}%";

            $blocks[] = '<b>' . $this->support->escapeHtml($label) . "</b>\n"
                . $barLine . "\n"
                . $this->support->escapeHtml(UserFormatter::formatMoney($user, $spent))
                . ' / '
                . $this->support->escapeHtml(UserFormatter::formatMoney($user, $limit));
        }

        if ($blocks === []) {
            $this->client->sendMessage($chatId, 'No active budgets');

            return;
        }

        $this->client->sendMessage(
            $chatId,
            "💰 <b>Budgets</b>\n\n" . implode("\n\n", $blocks),
            null,
            TelegramSupport::PARSE_HTML,
        );
    }

    public function sendRecurring(User $user, int|string $chatId): void
    {
        $rules = $this->listRecurring->execute($user);

        if ($rules->isEmpty()) {
            $this->client->sendMessage($chatId, 'No recurring rules');

            return;
        }

        $blocks = $rules->map(function (RecurringTransaction $rule) use ($user) {
            $status = $rule->active ? '✅' : '⏸';
            $money = $this->support->escapeHtml(UserFormatter::formatMoney($user, $rule->amount));
            $freq = $this->support->escapeHtml($rule->frequency->value);
            $next = $this->support->escapeHtml(UserFormatter::formatDate($user, $rule->next_run_at));

            $lines = [
                "{$status} <b>{$money}</b> · {$freq}",
                "next {$next}",
            ];

            if ($rule->note) {
                $lines[] = '📝 ' . $this->support->escapeHtml($rule->note);
            }

            return implode("\n", $lines);
        })->all();

        $this->client->sendMessage(
            $chatId,
            "🔁 <b>Recurring</b>\n\n" . implode("\n\n", $blocks),
            null,
            TelegramSupport::PARSE_HTML,
        );
    }

    public function handleNewTag(User $user, int|string $chatId, string $text): void
    {
        $payload = trim(substr($text, strlen('/newtag')));

        if ($payload === '') {
            $this->client->sendMessage(
                $chatId,
                "Usage: /newtag <emoji> <title> [> <parent title>]\nExample: /newtag 🍕 Fast Food > Food",
            );

            return;
        }

        $parentTitle = null;

        if (str_contains($payload, '>')) {
            [$payload, $parentTitle] = array_map('trim', explode('>', $payload, 2));
        }

        if (!preg_match('/^(\S+)\s+(.+)$/u', $payload, $matches)) {
            $this->client->sendMessage($chatId, 'Usage: /newtag <emoji> <title> [> <parent title>]');

            return;
        }

        $emoji = $matches[1];
        $title = trim($matches[2]);
        $parentId = null;

        if ($parentTitle !== null) {
            $parent = $this->listTags->execute($user)
                ->first(fn (Tag $tag) => mb_strtolower($tag->title) === mb_strtolower($parentTitle));

            if (!$parent) {
                $this->client->sendMessage($chatId, "Parent tag \"{$parentTitle}\" not found");

                return;
            }

            $parentId = $parent->id;
        }

        $tag = $this->createTag->execute($user, [
            'title' => $title,
            'emoji' => $emoji,
            'parent_id' => $parentId,
            'calc_balance' => true,
        ]);

        $this->client->sendMessage($chatId, "✅ Tag created: {$tag->emoji} {$tag->title}");
    }
}
