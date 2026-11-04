<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\CustomerPortalAccount;
use Illuminate\Console\Command;

class ClientAreaSetPassword extends Command
{
    protected $signature = 'client-area:set-password {customer_code : Customer code}';

    protected $description = 'Create/activate a client-area (/pelanggan) account and set its password (hidden input, never logged)';

    public function handle(): int
    {
        $customer = Customer::query()->where('code', (string) $this->argument('customer_code'))->first();
        if ($customer === null) {
            $this->error('Customer tidak ditemukan.');

            return self::FAILURE;
        }

        $password = (string) $this->secret('Password baru (min. 10 karakter)');
        if (mb_strlen($password) < 10) {
            $this->error('Password minimal 10 karakter.');

            return self::FAILURE;
        }
        $confirm = (string) $this->secret('Ulangi password');
        if (! hash_equals($password, $confirm)) {
            $this->error('Konfirmasi password tidak cocok.');

            return self::FAILURE;
        }

        $account = CustomerPortalAccount::query()->firstOrNew(['customer_id' => $customer->id]);
        $account->password = $password;
        $account->is_active = true;
        $account->failed_attempts = 0;
        $account->locked_until = null;
        $account->save();

        $this->info("Akun client area untuk {$customer->code} aktif.");

        return self::SUCCESS;
    }
}
