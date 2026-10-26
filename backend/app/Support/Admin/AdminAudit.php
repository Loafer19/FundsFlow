<?php

namespace App\Support\Admin;

use App\Models\AdminAuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Records admin actions in admin_audit_logs (shown in /admin → Audit log) and the app log.
 */
final class AdminAudit
{
    /**
     * @param array<string, mixed> $meta
     */
    public static function log(string $action, ?User $target, array $meta = [], ?User $admin = null): void
    {
        $admin ??= auth()->user() instanceof User ? auth()->user() : null;
        $ip = app()->runningInConsole() ? null : request()->ip();

        AdminAuditLog::create([
            'admin_id' => $admin?->id,
            'user_id' => $target?->id,
            'action' => $action,
            'meta' => $meta === [] ? null : $meta,
            'ip' => $ip,
        ]);

        Log::info('admin.' . $action, [
            'admin_id' => $admin?->id,
            'user_id' => $target?->id,
            'meta' => $meta,
            'ip' => $ip,
        ]);
    }
}
