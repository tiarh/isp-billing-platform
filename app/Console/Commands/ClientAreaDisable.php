<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\CustomerPortalAccount;
use Illuminate\Console\Command;

class ClientAreaDisable extends Command
{
    protected $signature = 'client-area:disable {customer_code : Customer code}';

    protected $description = 'Deactivate the client-area (/pelanggan) account of a customer';

    public function handle(): int
    {
        $customer = Customer::query()->where('code', (string) $this->argument('customer_code'))->first();
        $account = $customer
            ? CustomerPortalAccount::query()->where('customer_id', $customer->id)->first()
            : null;

        if ($account === null) {
            $this->error('Akun client area tidak ditemukan.');

            return self::FAILURE;
        }

        $account->is_active = false;
        $account->save();

        $this->info("Akun client area {$customer->code} dinonaktifkan.");

        return self::SUCCESS;
    }
}
