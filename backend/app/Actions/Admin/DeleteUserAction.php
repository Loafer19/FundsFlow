<?php

namespace App\Actions\Admin;

use App\Actions\Agent\Web\WebAgentUploads;
use App\Models\User;
use App\Support\Admin\AdminAudit;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Admin: permanently delete an account and everything it owns.
 *
 * Rows are removed explicitly (not only via FK cascades) so the result is the same on
 * MySQL and SQLite: transactions (+ tag links, attachments), tags (incl. soft-deleted),
 * budgets (+ periods, period tags), recurring rules (+ tags), identities (Google, GitHub,
 * Telegram links), Sanctum tokens (web, Mini App, MCP), password reset tokens, then the user.
 * The audit entry is written in the same DB transaction, before the user row goes.
 * Files (attachments, pending web-assistant uploads) and per-user cache keys are removed
 * after the commit, so a rolled-back delete never loses files.
 */
final class DeleteUserAction
{
    /**
     * Why $admin may not delete $user, or null when allowed.
     */
    public static function denialReason(User $user, ?User $admin): ?string
    {
        if ($admin === null) {
            return 'Only a signed-in admin can delete users.';
        }

        if ($user->is($admin)) {
            return 'You cannot delete your own account here.';
        }

        if ($user->is_admin) {
            return 'Admins cannot be deleted. Remove their admin role first.';
        }

        return null;
    }

    /**
     * @throws \DomainException when the deletion is not allowed
     *
     * @return array<string, int> counts of deleted rows/files
     */
    public function execute(User $user, ?User $admin = null): array
    {
        $admin ??= auth()->user() instanceof User ? auth()->user() : null;

        if ($reason = self::denialReason($user, $admin)) {
            throw new \DomainException($reason);
        }

        $userId = $user->id;
        $email = $user->email;
        $files = DB::table('transaction_attachments')
            ->where('user_id', $userId)
            ->get(['disk', 'path'])
            ->map(fn (object $row): array => ['disk' => (string) $row->disk, 'path' => (string) $row->path])
            ->all();
        $telegramChats = DB::table('identities')
            ->where('user_id', $userId)
            ->where('provider', 'telegram')
            ->pluck('external_id')
            ->all();
        $quotaKeys = $this->quotaKeys($user);

        $counts = DB::transaction(function () use ($user, $userId, $email, $admin): array {
            $transactionIds = DB::table('transactions')->where('user_id', $userId)->select('id');
            $tagIds = DB::table('tags')->where('user_id', $userId)->select('id');
            $budgetIds = DB::table('budgets')->where('user_id', $userId)->select('id');
            $periodIds = DB::table('budget_periods')->whereIn('budget_id', $budgetIds)->select('id');
            $recurringIds = DB::table('recurring_transactions')->where('user_id', $userId)->select('id');

            $counts = [
                'transactions' => DB::table('transactions')->where('user_id', $userId)->count(),
                'attachments' => DB::table('transaction_attachments')->where('user_id', $userId)->count(),
                'tags' => DB::table('tags')->where('user_id', $userId)->count(),
                'budgets' => DB::table('budgets')->where('user_id', $userId)->count(),
                'recurring' => DB::table('recurring_transactions')->where('user_id', $userId)->count(),
                'identities' => DB::table('identities')->where('user_id', $userId)->count(),
                'tokens' => $user->tokens()->count(),
            ];

            AdminAudit::log('user_deleted', $user, [
                'deleted_user_id' => $userId,
                'email' => $email,
                'name' => $user->name,
                'joined' => $user->created_at?->toDateString(),
                'counts' => $counts,
            ], $admin);

            // Revoke every Sanctum token (web, Mini App, MCP); tokens are polymorphic, no FK.
            $user->tokens()->delete();
            DB::table('password_reset_tokens')->where('email', $email)->delete();

            if (Schema::hasTable('sessions')) {
                DB::table('sessions')->where('user_id', $userId)->delete();
            }

            DB::table('tag_transaction')->whereIn('transaction_id', $transactionIds)->delete();
            DB::table('tag_transaction')->whereIn('tag_id', $tagIds)->delete();
            DB::table('transaction_attachments')->where('user_id', $userId)->delete();
            DB::table('transaction_attachments')->whereIn('transaction_id', $transactionIds)->delete();
            DB::table('budget_period_tag')->whereIn('budget_period_id', $periodIds)->delete();
            DB::table('budget_period_tag')->whereIn('tag_id', $tagIds)->delete();
            DB::table('budget_periods')->whereIn('budget_id', $budgetIds)->delete();
            DB::table('recurring_transaction_tag')->whereIn('recurring_transaction_id', $recurringIds)->delete();
            DB::table('recurring_transaction_tag')->whereIn('tag_id', $tagIds)->delete();

            DB::table('transactions')->where('user_id', $userId)->delete();
            DB::table('budgets')->where('user_id', $userId)->delete();
            DB::table('recurring_transactions')->where('user_id', $userId)->delete();
            // Self-referencing parent_id: detach first so no row points at one being deleted.
            DB::table('tags')->where('user_id', $userId)->update(['parent_id' => null]);
            DB::table('tags')->where('user_id', $userId)->delete();
            DB::table('identities')->where('user_id', $userId)->delete();

            DB::table('users')->where('id', $userId)->delete();

            return $counts;
        });

        $counts['files'] = $this->deleteFiles($userId, $files);
        $this->forgetCache($userId, $telegramChats, $quotaKeys);

        Log::info('admin.user_deleted.done', ['deleted_user_id' => $userId, 'counts' => $counts]);

        return $counts;
    }

    /**
     * @param list<array{disk: string, path: string}> $files
     */
    private function deleteFiles(int $userId, array $files): int
    {
        $deleted = 0;

        foreach ($files as $file) {
            try {
                if (Storage::disk($file['disk'])->delete($file['path'])) {
                    $deleted++;
                }
            } catch (Throwable $e) {
                Log::warning('admin.user_deleted.file_failed', ['user_id' => $userId, 'path' => $file['path'], 'error' => $e->getMessage()]);
            }
        }

        try {
            // Attachment folders (attachments/{user}/{transaction}/…) on every disk that was used.
            $disks = array_unique([...array_column($files, 'disk'), (string) config('filesystems.default', 'local')]);

            foreach ($disks as $disk) {
                Storage::disk($disk)->deleteDirectory('attachments/' . $userId);
            }

            // Pending receipt photos / documents of the web assistant.
            Storage::disk(WebAgentUploads::DISK)->deleteDirectory(WebAgentUploads::DIR . '/' . $userId);
        } catch (Throwable $e) {
            Log::warning('admin.user_deleted.dir_failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }

        return $deleted;
    }

    /**
     * AI quota + bonus keys for yesterday/today/tomorrow in the user's timezone.
     *
     * @return list<string>
     */
    private function quotaKeys(User $user): array
    {
        $keys = [];

        foreach ([-1, 0, 1] as $offset) {
            $day = $user->nowInTimezone()->addDays($offset)->toDateString();
            $keys[] = 'telegram_ai:' . $user->id . ':' . $day;
            $keys[] = 'telegram_ai_bonus:' . $user->id . ':' . $day;
        }

        return $keys;
    }

    /**
     * @param list<string> $telegramChats
     * @param list<string> $quotaKeys
     */
    private function forgetCache(int $userId, array $telegramChats, array $quotaKeys): void
    {
        $keys = [
            ...$quotaKeys,
            'agent_history:' . $userId,
            'web_agent_session:' . $userId,
        ];

        foreach ($telegramChats as $chatId) {
            array_push(
                $keys,
                "telegram_assistant_session:{$chatId}:{$chatId}",
                "telegram_pending_media:{$chatId}",
                "telegram_pending_media_await_text:{$chatId}",
                "telegram_receipt_draft:{$chatId}",
                "telegram_album_queue:{$chatId}",
                "telegram_edit_amount:{$chatId}",
            );
        }

        foreach ($keys as $key) {
            try {
                Cache::forget($key);
            } catch (Throwable $e) {
                Log::warning('admin.user_deleted.cache_failed', ['user_id' => $userId, 'key' => $key, 'error' => $e->getMessage()]);
            }
        }
    }
}
