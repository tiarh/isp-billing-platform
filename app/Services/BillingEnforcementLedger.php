<?php

namespace App\Services;

use App\Models\BillingEnforcementAction;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Schema;

class BillingEnforcementLedger
{
    public function key(int $customerId, int $invoiceId, string $action): string
    {
        return sprintf('billing:%d:%d:%s:%s', $customerId, $invoiceId, $action, BillingEligibilityService::POLICY_VERSION);
    }

    public function start(int $customerId, int $invoiceId, string $action): ?BillingEnforcementAction
    {
        if (! Schema::hasTable('billing_enforcement_actions')) {
            throw new ModelNotFoundException('billing_enforcement_actions migration is required');
        }
        $row = BillingEnforcementAction::query()->firstOrCreate(
            ['idempotency_key' => $this->key($customerId, $invoiceId, $action)],
            [
                'customer_id' => $customerId,
                'invoice_id' => $invoiceId,
                'action' => $action,
                'policy_version' => BillingEligibilityService::POLICY_VERSION,
                'status' => 'pending',
            ],
        );
        if ($row->status === 'succeeded') {
            return null;
        }
        $row->forceFill(['status' => 'running', 'started_at' => now(), 'error' => null])->save();

        return $row;
    }

    public function succeeded(BillingEnforcementAction $row, array $metadata = []): void
    {
        $row->forceFill(['status' => 'succeeded', 'metadata' => $metadata, 'completed_at' => now(), 'error' => null])->save();
    }

    public function failed(BillingEnforcementAction $row, \Throwable $error): void
    {
        $row->forceFill(['status' => 'failed', 'error' => $error->getMessage(), 'completed_at' => now()])->save();
    }
}
