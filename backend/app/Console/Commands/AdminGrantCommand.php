<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Admin\AdminAudit;
use Illuminate\Console\Command;

/**
 * Make an existing account an admin of /admin (or take it away).
 *
 *   php artisan admin:grant you@example.com              # grant; asks whether to set a panel password
 *   php artisan admin:grant you@example.com --password   # grant + always set a password
 *   php artisan admin:grant you@example.com --reset-mfa  # lost authenticator: set it up again on next sign-in
 *   php artisan admin:grant you@example.com --revoke
 */
class AdminGrantCommand extends Command
{
    protected $signature = 'admin:grant
        {email : Email of an existing FundsFlow account}
        {--revoke : Remove admin access instead}
        {--password : Prompt for a new password (needed when the account only signs in with Telegram / Google / GitHub)}
        {--reset-mfa : Clear the authenticator app + recovery codes}';

    protected $description = 'Grant or revoke access to the Filament admin panel (/admin)';

    public function handle(): int
    {
        $user = User::query()->where('email', (string) $this->argument('email'))->first();

        if (!$user) {
            $this->error('No user with that email. Telegram-only accounts have an address like telegram-…@fundsflow.invalid; find it in the users table.');

            return self::FAILURE;
        }

        if ($this->option('revoke')) {
            $user->forceFill(['is_admin' => false])->save();
            AdminAudit::log('admin_revoked', $user, ['via' => 'console']);
            $this->info("{$user->email} is no longer an admin.");

            return self::SUCCESS;
        }

        $user->forceFill(['is_admin' => true])->save();
        AdminAudit::log('admin_granted', $user, ['via' => 'console']);
        $this->info("{$user->email} is now an admin.");

        if ($this->option('reset-mfa')) {
            $user->saveAppAuthenticationSecret(null);
            $user->saveAppAuthenticationRecoveryCodes(null);
            AdminAudit::log('admin_mfa_reset', $user, ['via' => 'console']);
            $this->info('Authenticator app cleared: it will be set up again on the next sign-in.');
        }

        $askPassword = $this->option('password') || ($this->input->isInteractive() && $this->confirm(
            'Set a new password for the admin login? (Telegram / Google / GitHub accounts have a random one nobody knows)',
            $user->identities()->exists(),
        ));

        if ($askPassword) {
            $password = (string) $this->secret('New password (min 12 characters)');

            if (mb_strlen($password) < 12 || $password !== (string) $this->secret('Repeat it')) {
                $this->error('Passwords too short or did not match; the password was not changed.');

                return self::FAILURE;
            }

            // Also the web app password for this account (same users.password).
            $user->forceFill(['password' => $password])->save();
            AdminAudit::log('admin_password_set', $user, ['via' => 'console']);
            $this->info('Password set. Sign in at ' . url('/admin') . ' and scan the QR code with an authenticator app.');
        }

        if ($user->isBlocked()) {
            $this->warn('This account is blocked, so it still cannot open the panel.');
        }

        return self::SUCCESS;
    }
}
