<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * "Last seen" stamp for the admin dashboard (users.last_active_at).
 *
 * Web / Mini App / MCP requests are already visible through Sanctum's
 * personal_access_tokens.last_used_at; this covers Telegram chats, which don't use tokens.
 * Writes are throttled to one per user per five minutes and never bump updated_at.
 */
class UserActivity
{
    public const THROTTLE_SECONDS = 300;

    public static function touch(User $user): void
    {
        try {
            if (!Cache::add("user_active:{$user->getKey()}", 1, self::THROTTLE_SECONDS)) {
                return;
            }

            DB::table('users')->where('id', $user->getKey())->update(['last_active_at' => now()]);
        } catch (QueryException) {
            // Column not migrated yet: activity tracking is best-effort.
        } catch (Throwable $e) {
            report($e);
        }
    }
}
