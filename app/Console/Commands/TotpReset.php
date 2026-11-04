<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\TotpService;
use Illuminate\Console\Command;

class TotpReset extends Command
{
    protected $signature = 'totp:reset {user : User ID or email address}';

    protected $description = 'Emergency reset of one user TOTP enrollment';

    public function handle(TotpService $totp): int
    {
        $identifier = (string) $this->argument('user');
        $user = User::query()
            ->whereKey(is_numeric($identifier) ? (int) $identifier : 0)
            ->orWhere('email', $identifier)
            ->first();

        if (! $user) {
            $this->error('User tidak ditemukan.');

            return self::FAILURE;
        }

        if (! $this->confirm('Reset TOTP untuk akun ini?', false)) {
            $this->warn('Reset dibatalkan.');

            return self::INVALID;
        }

        $totp->reset($user);
        activity('totp.reset')
            ->performedOn($user)
            ->withProperties([
                'policy_version' => config('totp.policy_version'),
                'target_user_id' => $user->id,
                'reset_source' => 'artisan_break_glass',
            ])
            ->log('totp.reset');

        $this->info('TOTP berhasil di-reset.');

        return self::SUCCESS;
    }
}
