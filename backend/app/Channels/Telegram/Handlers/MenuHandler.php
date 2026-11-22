<?php

namespace App\Channels\Telegram\Handlers;

use App\Actions\Budgets\CalculateGoalProgressAction;
use App\Actions\Budgets\CalculateLimitStatusAction;
use App\Actions\Budgets\ListBudgetsAction;
use App\Actions\RecurringTransactions\ListRecurringTransactionsAction;
use App\Actions\Tags\CreateTagAction;
use App\Actions\Tags\ListTagsAction;
use App\Actions\Transactions\ListTransactionsAction;
use App\Channels\Telegram\RichCards;
use App\Channels\Telegram\TelegramChatSession;
use App\Channels\Telegram\TelegramClient;
use App\Channels\Telegram\TelegramSupport;
use App\Channels\Telegram\Widgets\TelegramWidgetSender;
use App\Channels\Telegram\Widgets\WidgetAvailability;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\RecurringTransaction;
use App\Models\Tag;
use App\Models\Transaction;
use App\Models\User;
use App\Support\GoalSummary;
use App\Support\RecurringTypeData;
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
        private readonly CalculateLimitStatusAction $limitStatus,
        private readonly TelegramChatSession $session,
        private readonly CalculateGoalProgressAction $goalProgress,
        private readonly TelegramWidgetSender $widgets,
    ) {}

    public function sendMonthSummary(User $user, int|string $chatId, int|string|null $telegramUserId = null): void
    {
        $identity = $telegramUserId ?? $chatId;

        if ($this->isPrivateChat($chatId) && $this->widgets->sendSummary($user, $chatId)) {
            $this->session->setDomain($identity, $chatId, 'analytics');
            $this->session->setSummary($identity, $chatId, 'Month summary sent.');

            return;
        }

        $card = RichCards::monthSummary($user);
        $this->client->sendRichOrHtml($chatId, $card['rich'], $card['html']);
        $this->session->setDomain($identity, $chatId, 'analytics');
        $this->session->setSummary($identity, $chatId, 'Month summary sent.');
    }

    public function sendTags(User $user, int|string $chatId, int|string|null $telegramUserId = null): void
    {
        $tags = $this->listTags->execute($user);
        $identity = $telegramUserId ?? $chatId;
        $tree = $this->support->buildTagTree($tags);
        $this->session->setDomain($identity, $chatId, 'tags');
        $this->session->setLastList($identity, $chatId, array_map(
            static fn (array $node): array => [
                'id' => (int) $node['tag']->id,
                'title' => (string) $node['tag']->title,
                'emoji' => (string) $node['tag']->emoji,
            ],
            $tree,
        ));

        if ($tags->isNotEmpty() && $this->isPrivateChat($chatId) && $this->widgets->sendTags($user, $chatId)) {
            $this->session->setSummary($identity, $chatId, 'Tags with this month\'s expenses shown.');

            return;
        }

        if ($tags->isEmpty()) {
            $this->session->setSummary($identity, $chatId, 'No tags yet.');
            $this->client->sendMessage($chatId, 'No tags yet');

            return;
        }

        $lines = array_map(
            fn (array $node) => $this->support->formatTagLabel($node['tag'], $node['depth']),
            $tree,
        );

        $summary = "🏷 Your tags\n" . implode("\n", $lines);
        $this->client->sendMessage($chatId, $summary);
        $this->session->setSummary($identity, $chatId, $summary);
    }

    public function sendRecent(User $user, int|string $chatId, int|string|null $telegramUserId = null): void
    {
        $identity = $telegramUserId ?? $chatId;

        if ($this->isPrivateChat($chatId) && $this->widgets->sendRecent($user, $chatId)) {
            $payload = $this->recentListPayload($user);
            $this->session->setDomain($identity, $chatId, 'transactions');
            $this->session->setLastList($identity, $chatId, is_array($payload) ? $payload['list'] : []);
            $this->session->setSummary(
                $identity,
                $chatId,
                $payload === null ? 'No transactions yet.' : 'Recent transactions listed.',
            );

            return;
        }

        $payload = $this->recentListPayload($user);

        if ($payload === null) {
            $this->session->setDomain($identity, $chatId, 'transactions');
            $this->session->setLastList($identity, $chatId, []);
            $this->session->setSummary($identity, $chatId, 'No transactions yet.');
            $this->client->sendMessage($chatId, 'No transactions yet');

            return;
        }

        $this->session->setDomain($identity, $chatId, 'transactions');
        $this->session->setLastList($identity, $chatId, $payload['list']);
        $this->session->setSummary($identity, $chatId, 'Recent transactions listed.');
        $this->client->sendRichOrHtml($chatId, $payload['rich'], $payload['text'], $payload['reply_markup']);
    }

    /**
     * @return null|array{text: string, rich: string, reply_markup: array<string, mixed>, list: list<array{id: int, title: string}>}
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

        $list = $transactions->map(function (Transaction $transaction) use ($user): array {
            $title = trim((string) ($transaction->note ?? ''));

            if ($title === '') {
                $title = UserFormatter::formatMoney($user, $transaction->amount);
            }

            return [
                'id' => (int) $transaction->id,
                'title' => $title,
            ];
        })->all();

        $rows = $transactions->map(function (Transaction $transaction, int $index) use ($user): string {
            $tags = $transaction->tags->map(fn ($tag) => trim($tag->emoji . ' ' . $tag->title))->take(2)->implode(' · ');

            return '<tr><td>' . ($index + 1) . '</td>'
                . '<td><code>' . $transaction->at->format('d.m') . '</code></td>'
                . '<td><b>' . RichCards::esc($this->support->signedMoney($user, (float) $transaction->amount)) . '</b></td>'
                . '<td>' . RichCards::esc($this->support->truncateNote($transaction->note) ?: '—')
                . ($tags !== '' ? '<br><i>' . RichCards::esc($tags) . '</i>' : '') . '</td></tr>';
        })->implode('');

        return [
            'text' => "🕘 <b>Recent</b>\n\n" . implode("\n\n", $items),
            'rich' => '<h3>🕘 Recent</h3><table striped compact>' . $rows . '</table>',
            'reply_markup' => ['inline_keyboard' => array_chunk($buttons, 5)],
            'list' => $list,
        ];
    }

    public function sendBudgets(User $user, int|string $chatId, int|string|null $telegramUserId = null): void
    {
        $identity = $telegramUserId ?? $chatId;
        $budgets = $this->listBudgets->execute($user);
        $blocks = [];
        $goals = [];
        $richLimits = [];
        $richGoals = [];
        $list = [];

        foreach ($budgets as $budget) {
            $period = $budget->periods->first(fn (BudgetPeriod $period) => $period->ends_at === null);

            if (!$period) {
                continue;
            }

            $label = $budget->label($period);

            if ($budget->isGoal()) {
                $goals[] = $this->goalBlock($user, $budget, $label);
                $richGoals[] = $this->goalRow($user, $budget, $label);
                $list[] = ['id' => (int) $budget->id, 'title' => $label];

                continue;
            }

            $blocks[] = $this->limitBlock($user, $period, $label);
            $richLimits[] = $this->limitRow($user, $period, $label);

            $list[] = [
                'id' => (int) $budget->id,
                'title' => $label,
            ];
        }

        $this->session->setDomain($identity, $chatId, 'budgets');
        $this->session->setLastList($identity, $chatId, $list);

        if ($this->isPrivateChat($chatId) && $this->widgets->sendBudgets($user, $chatId)) {
            $this->session->setSummary(
                $identity,
                $chatId,
                $blocks === [] && $goals === [] ? 'No active budgets or savings goals.' : 'Budgets listed.',
            );

            return;
        }

        if ($blocks === [] && $goals === []) {
            $this->session->setSummary($identity, $chatId, 'No active budgets or savings goals.');
            $this->client->sendMessage($chatId, 'No active budgets or savings goals');

            return;
        }

        $sections = [];
        $rich = '';

        if ($blocks !== []) {
            $sections[] = "💰 <b>Limits</b>\n\n" . implode("\n\n", $blocks);
            $rich .= '<h3>💰 Limits</h3><table striped compact>' . implode('', $richLimits) . '</table>';
        }

        if ($goals !== []) {
            $sections[] = "🎯 <b>Savings goals</b>\n\n" . implode("\n\n", $goals);
            $rich .= '<h3>🎯 Savings goals</h3><table striped compact>' . implode('', $richGoals) . '</table>';
        }

        $this->session->setSummary($identity, $chatId, 'Budgets listed.');
        $this->client->sendRichOrHtml($chatId, $rich, implode("\n\n", $sections));
    }

    public function sendRecurring(User $user, int|string $chatId): void
    {
        $rules = $this->listRecurring->execute($user);

        if ($rules->isNotEmpty() && $this->isPrivateChat($chatId) && $this->widgets->sendRecurring($user, $chatId)) {
            return;
        }

        if ($rules->isEmpty()) {
            $this->client->sendMessage($chatId, 'No recurring rules');

            return;
        }

        $blocks = $rules->map(function (RecurringTransaction $rule) use ($user) {
            $status = $rule->active ? '✅' : '⏸';
            $money = $this->support->escapeHtml(UserFormatter::formatMoney($user, $rule->amount));
            $freq = $this->support->escapeHtml($rule->frequency->value);
            $next = $this->support->escapeHtml(TelegramSupport::cardDate($rule->next_run_at));

            $lines = [
                "{$status} <b>{$money}</b> · {$freq}",
                "next {$next}",
            ];

            $typeLine = RecurringTypeData::typeLine($rule);

            if ($typeLine) {
                $lines[] = $this->support->escapeHtml($typeLine);
            }

            if ($rule->note) {
                $lines[] = '📝 ' . $this->support->escapeHtml($rule->note);
            }

            return implode("\n", $lines);
        })->all();

        $rows = $rules->map(function (RecurringTransaction $rule) use ($user): string {
            $type = RecurringTypeData::typeLine($rule);
            $detail = trim(($rule->note ?? '') . ($type ? ($rule->note ? ' · ' : '') . $type : ''));

            return '<tr><td>' . ($rule->active ? '✅' : '⏸') . '</td>'
                . '<td><b>' . RichCards::esc($this->support->signedMoney($user, (float) $rule->amount)) . '</b><br><i>' . RichCards::esc($rule->frequency->value) . '</i></td>'
                . '<td><code>' . RichCards::esc($rule->active ? TelegramSupport::cardDate($rule->next_run_at) : 'paused') . '</code></td>'
                . '<td>' . RichCards::esc($detail !== '' ? $detail : '—') . '</td></tr>';
        })->implode('');

        $this->client->sendRichOrHtml(
            $chatId,
            '<h3>🔁 Recurring</h3><table striped compact>' . $rows . '</table>',
            "🔁 <b>Recurring</b>\n\n" . implode("\n\n", $blocks),
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

    /**
     * One switch for limit alerts, goal messages, the weekly digest, and recurring / bank sync
     * notifications (muted per chat). /mute and /unmute still work as shortcuts.
     */
    public function sendNotifications(User $user, int|string $chatId): void
    {
        if ($this->isPrivateChat($chatId) && $this->widgets->sendNotifications($user, $chatId)) {
            return;
        }

        $muted = (bool) ($this->support->resolveIdentity($chatId)?->meta['muted'] ?? false);
        $this->client->sendMessage(
            $chatId,
            $muted
                ? '🔕 Notifications are muted in this chat'
                : '🔔 Notifications are on in this chat',
            $this->muteKeyboard($muted),
        );
    }

    public function handleMuteButton(User $user, int|string $chatId, ?int $messageId, string $callbackId, bool $muted): void
    {
        $identity = $this->support->resolveIdentity($chatId);

        if (!$identity) {
            $this->client->answerCallbackQuery($callbackId, "Account isn't linked.");

            return;
        }

        $identity->update(['meta' => [...($identity->meta ?? []), 'muted' => $muted]]);
        $this->client->answerCallbackQuery($callbackId, $muted ? 'Notifications muted!' : 'Notifications unmuted!');

        if ($messageId) {
            $this->client->editMessageText(
                $chatId,
                $messageId,
                $muted
                    ? '🔕 Notifications are muted in this chat'
                    : '🔔 Notifications are on in this chat',
                $this->muteKeyboard($muted),
            );
        }
    }

    public function sendAddWidget(User $user, int|string $chatId): void
    {
        if ($this->isPrivateChat($chatId) && $this->widgets->sendAdd($user, $chatId)) {
            return;
        }

        $this->client->sendMessage(
            $chatId,
            TelegramSupport::isPrivateChat($chatId) && WidgetAvailability::blocked()
                ? "The keypad isn't available right now — Telegram is refusing widgets. Send the amount like 350 groceries or +15000 salary."
                : 'Quick add works as an interactive widget in a private chat with the bot. '
                    . 'Here, just write it, like 350 groceries or +15000 salary.',
        );
    }

    /**
     * <b>Groceries</b>
     * ███████░░░ 72%
     * ₴7,200 / ₴10,000 · ₴2,800 left
     * Oct 1 – Oct 31 · 5 days left
     */
    public function limitBlock(User $user, BudgetPeriod $period, string $label): string
    {
        $st = $this->limitStatus->execute($period, $user);
        $esc = fn (string $text): string => $this->support->escapeHtml($text);
        $money = fn (float $v): string => $esc(UserFormatter::formatMoney($user, $v));
        $balance = $st['left'] >= 0 ? $money($st['left']) . ' left' : $money(-$st['left']) . ' over';
        $days = $st['days_left'] === 1 ? 'last day' : $st['days_left'] . ' days left';
        $scopeLine = '';

        if ($period->scope) {
            $scopeLabel = $period->scopeLabel();

            if ($scopeLabel !== '' && $scopeLabel !== $label) {
                $scopeLine = "\n<i>" . $esc($scopeLabel) . '</i>';
            }
        }

        return '<b>' . $esc($label) . '</b>' . $scopeLine . "\n"
            . $this->support->progressBar($st['limit'] > 0 ? $st['spent'] / $st['limit'] : 0.0) . ' ' . $st['percent'] . "%\n"
            . $money($st['spent']) . ' / ' . $money($st['limit']) . ' · ' . $balance . "\n"
            . $esc(TelegramSupport::cardDate($st['start']) . ' – ' . TelegramSupport::cardDate($st['end'])) . ' · ' . $days;
    }

    public function goalBlock(User $user, Budget $budget, string $label): string
    {
        $progress = $this->goalProgress->execute($budget, $user);
        $ratio = $progress['target'] > 0 ? min(1.0, $progress['saved'] / $progress['target']) : 0.0;
        $lines = GoalSummary::lines($user, $progress);

        return '<b>' . $this->support->escapeHtml($label) . "</b>\n"
            . $this->support->progressBar($ratio) . ' ' . $progress['percent'] . "%\n"
            . implode("\n", array_map(fn (string $line) => $this->support->escapeHtml($line), $lines));
    }

    /** One rich table row per limit: name, bar and %, spent / limit, left and days. */
    private function limitRow(User $user, BudgetPeriod $period, string $label): string
    {
        $st = $this->limitStatus->execute($period, $user);
        $money = fn (float $v): string => RichCards::esc(UserFormatter::formatMoney($user, $v));
        $tone = match ($st['status']) {
            'over' => '🔴 ',
            'at_risk' => '🟠 ',
            default => '',
        };
        $balance = $st['left'] >= 0 ? $money($st['left']) . ' left' : $money(-$st['left']) . ' over';
        $days = $st['days_left'] === 1 ? 'last day' : $st['days_left'] . ' days left';

        return '<tr><td>' . $tone . '<b>' . RichCards::esc($label) . '</b></td>'
            . '<td><code>' . RichCards::bar($st['limit'] > 0 ? $st['spent'] / $st['limit'] : 0.0, 8) . '</code> ' . $st['percent'] . '%</td>'
            . '<td>' . $money($st['spent']) . ' / ' . $money($st['limit']) . '<br><i>' . $balance . ' · ' . $days . '</i></td></tr>';
    }

    private function goalRow(User $user, Budget $budget, string $label): string
    {
        $progress = $this->goalProgress->execute($budget, $user);
        $ratio = $progress['target'] > 0 ? min(1.0, $progress['saved'] / $progress['target']) : 0.0;
        $money = fn (float $v): string => RichCards::esc(UserFormatter::formatMoney($user, $v));

        return '<tr><td>' . ($progress['status'] === 'completed' ? '🟢 ' : '') . '<b>' . RichCards::esc($label) . '</b></td>'
            . '<td><code>' . RichCards::bar($ratio, 8) . '</code> ' . $progress['percent'] . '%</td>'
            . '<td>' . $money($progress['saved']) . ' / ' . $money($progress['target']) . '<br><i>' . RichCards::esc(GoalSummary::statusLabel($progress['status'])) . '</i></td></tr>';
    }

    /**
     * @return array<string, mixed>
     */
    private function muteKeyboard(bool $muted): array
    {
        return ['inline_keyboard' => [[
            $muted
                ? ['text' => '🔔 Unmute', 'callback_data' => 'notify:unmute']
                : ['text' => '🔕 Mute', 'callback_data' => 'notify:mute'],
        ]]];
    }

    /**
     * Widgets currently work in production only in the private chat with the bot.
     * Telegram private chats use the user's positive id as chat_id.
     */
    private function isPrivateChat(int|string $chatId): bool
    {
        return (int) $chatId > 0;
    }
}
