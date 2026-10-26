<?php

namespace App\Channels\Telegram;

use App\Actions\Agent\AgentHistory;
use App\Models\Tag;
use App\Models\Transaction;
use App\Models\User;
use App\Support\UserFormatter;

/**
 * One-line notes about non-AI Telegram actions (quick add, menu views, receipts) for the
 * assistant memory shared with the web chat (AgentHistory), so "the one I just added" works
 * in either place. Private chats only (a private chat's id is the user's positive Telegram id;
 * group ids are negative).
 */
final class TelegramHistory
{
    private const MAX_LINE = 200;

    public function __construct(
        private readonly AgentHistory $history,
    ) {}

    public static function isPrivate(int|string $chatId): bool
    {
        return (int) $chatId > 0;
    }

    /**
     * What the user typed or sent, as role user.
     */
    public function input(int|string $chatId, ?User $user, string $text): void
    {
        $this->add($chatId, $user, 'user', $text);
    }

    /**
     * What the bot did, as role assistant ("Saved: -350.00 ₴ groceries (Food)").
     */
    public function outcome(int|string $chatId, ?User $user, string $text): void
    {
        $this->add($chatId, $user, 'assistant', $text);
    }

    /**
     * A staged change waiting for Confirm (status follows the Confirm / Cancel tap by its nonce).
     */
    public function proposal(int|string $chatId, ?User $user, string $text, string $nonce): void
    {
        if ($user === null || !self::isPrivate($chatId)) {
            return;
        }
        $this->history->addProposal($user, AgentHistory::TELEGRAM, mb_substr(trim($text), 0, self::MAX_LINE), $nonce);
    }

    /**
     * "-350.00 ₴ groceries (🍔 Food) · 20.08.2026".
     */
    public static function transactionLine(User $user, Transaction $transaction): string
    {
        $line = UserFormatter::formatMoney($user, $transaction->amount);
        if ($transaction->note) {
            $line .= ' ' . $transaction->note;
        }
        $tags = $transaction->tags
            ->map(static fn (Tag $tag): string => trim($tag->emoji . ' ' . $tag->title))
            ->implode(', ');
        $line .= ' (' . ($tags !== '' ? $tags : 'untagged') . ')';

        return $line . ' · ' . UserFormatter::formatDate($user, $transaction->at) . " · id {$transaction->id}";
    }

    private function add(int|string $chatId, ?User $user, string $role, string $text): void
    {
        if ($user === null || !self::isPrivate($chatId)) {
            return;
        }
        $line = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if ($line === '') {
            return;
        }
        if (mb_strlen($line) > self::MAX_LINE) {
            $line = mb_substr($line, 0, self::MAX_LINE) . '…';
        }

        $this->history->add($user, AgentHistory::TELEGRAM, $role, $line);
    }
}
