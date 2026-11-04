<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class RecoveryE2ESeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('testing')) {
            throw new RuntimeException('RecoveryE2ESeeder hanya boleh dijalankan pada APP_ENV=testing.');
        }

        $password = (string) env('E2E_PASSWORD');
        if ($password === '') {
            throw new RuntimeException('E2E_PASSWORD wajib tersedia untuk fixture recovery sandbox.');
        }

        DB::transaction(function () use ($password): void {
            $now = now();
            $passwordHash = Hash::make($password);

            $adminId = $this->upsertUser('admin@local', 'E2E Admin', 'superadmin', $passwordHash, $now);
            $technicianId = $this->upsertUser(
                'e2e-technician@example.test',
                'E2E Technician',
                'teknisi',
                $passwordHash,
                $now,
            );
            $scopedAdminId = $this->upsertUser(
                'e2e-scoped-admin@example.test',
                'E2E Scoped Admin',
                'admin',
                $passwordHash,
                $now,
            );

            $areaId = $this->upsertArea('E2E', 'E2E Area', $now);
            $otherAreaId = $this->upsertArea('E2E-OTHER', 'E2E Other Area', $now);
            DB::table('area_user')->updateOrInsert(
                ['area_id' => $areaId, 'user_id' => $scopedAdminId],
                ['created_at' => $now, 'updated_at' => $now],
            );

            DB::table('packages')->updateOrInsert(
                ['code' => 'E2E-50M'],
                [
                    'name' => 'Paket E2E 50M',
                    'description' => 'Isolated E2E fixture',
                    'price' => 150000,
                    'speed_mbps' => 50,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
            $packageId = (int) DB::table('packages')->where('code', 'E2E-50M')->value('id');

            $customerId = $this->upsertCustomer(
                'E2E-CUSTOMER',
                'Pelanggan E2E Recovery',
                $areaId,
                $packageId,
                'HWTC-E2E-0001',
                'e2e-customer-1',
                $now,
            );
            $otherCustomerId = $this->upsertCustomer(
                'E2E-CUSTOMER-2',
                'Pelanggan E2E Other Area',
                $otherAreaId,
                $packageId,
                'HWTC-E2E-0002',
                'e2e-customer-2',
                $now,
            );

            // Own customer + serial: the 30-day duplicate link would otherwise
            // fold the race ticket into the earlier fixture cases.
            $raceCustomerId = $this->upsertCustomer(
                'E2E-CUSTOMER-3',
                'Pelanggan E2E Race',
                $areaId,
                $packageId,
                'HWTC-E2E-0003',
                'e2e-customer-3',
                $now,
            );

            $this->removePreviousFixtures();

            $ticketIds = [];
            $ticketIds['TCK-E2E-001'] = $this->createApprovedTicket(
                'TCK-E2E-001',
                $customerId,
                $areaId,
                $adminId,
                $technicianId,
                'Dismantle E2E',
                'assigned',
                $now,
            );
            $ticketIds['TCK-E2E-ADMIN-001'] = $this->createApprovedTicket(
                'TCK-E2E-ADMIN-001',
                $otherCustomerId,
                $otherAreaId,
                $adminId,
                null,
                'Dismantle admin E2E',
                'closed',
                $now,
            );
            $ticketIds['TCK-E2E-EXCEPTION-001'] = $this->createApprovedTicket(
                'TCK-E2E-EXCEPTION-001',
                $customerId,
                $areaId,
                $adminId,
                $technicianId,
                'Dismantle exception E2E',
                'closed',
                $now,
            );
            $ticketIds['TCK-E2E-CONCURRENT-001'] = $this->createApprovedTicket(
                'TCK-E2E-CONCURRENT-001',
                $customerId,
                $areaId,
                $adminId,
                null,
                'Dismantle concurrent E2E',
                'closed',
                $now,
            );
            $ticketIds['TCK-E2E-RACE-001'] = $this->createApprovedTicket(
                'TCK-E2E-RACE-001',
                $raceCustomerId,
                $areaId,
                $adminId,
                null,
                'Dismantle race E2E',
                'closed',
                $now,
            );

            $caseId = DB::table('device_recovery_cases')->insertGetId([
                'ticket_id' => $ticketIds['TCK-E2E-001'],
                'customer_id' => $customerId,
                'area_id' => $areaId,
                'status' => 'assigned',
                'assigned_to' => $technicianId,
                'created_by' => $adminId,
                'version' => 1,
                'meta' => json_encode(['source' => 'approved_dismantle'], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('device_recovery_items')->insert([
                'recovery_case_id' => $caseId,
                'device_type' => 'onu',
                'expected_serial_number' => 'HWTC-E2E-0001',
                'expected_source' => 'customer',
                'status' => 'pending',
                'condition' => 'unknown',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    private function upsertUser(string $email, string $name, string $role, string $password, mixed $now): int
    {
        DB::table('users')->updateOrInsert(
            ['email' => $email],
            [
                'name' => $name,
                'password' => $password,
                'role' => $role,
                'email_verified_at' => $now,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );

        return (int) DB::table('users')->where('email', $email)->value('id');
    }

    private function upsertArea(string $code, string $name, mixed $now): int
    {
        DB::table('areas')->updateOrInsert(
            ['code' => $code],
            ['name' => $name, 'updated_at' => $now, 'created_at' => $now],
        );

        return (int) DB::table('areas')->where('code', $code)->value('id');
    }

    private function upsertCustomer(
        string $code,
        string $name,
        int $areaId,
        int $packageId,
        string $serial,
        string $pppoeUser,
        mixed $now,
    ): int {
        DB::table('customers')->updateOrInsert(
            ['code' => $code],
            [
                'name' => $name,
                'email' => $pppoeUser.'@example.test',
                'phone' => '0800000000',
                'address' => 'E2E fixture address',
                'area_id' => $areaId,
                'package_id' => $packageId,
                'pppoe_user' => $pppoeUser,
                'status' => 'terminated',
                'onu_serial' => $serial,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        return (int) DB::table('customers')->where('code', $code)->value('id');
    }

    private function createApprovedTicket(
        string $code,
        int $customerId,
        int $areaId,
        int $adminId,
        ?int $assignedTo,
        string $subject,
        string $status,
        mixed $now,
    ): int {
        $ticketId = (int) DB::table('support_tickets')->insertGetId([
            'code' => $code,
            'report_id' => $code,
            'customer_id' => $customerId,
            'customer_name' => str_replace('Dismantle', 'Pelanggan', $subject),
            'customer_phone' => '0800000000',
            'assigned_to' => $assignedTo,
            'created_by' => $adminId,
            'area_id' => $areaId,
            'type' => 'request',
            'priority' => 'normal',
            'status' => $status,
            'subject' => $subject,
            'description' => 'Isolated recovery fixture',
            'gangguan' => $subject,
            'due_at' => $now->copy()->addDay(),
            'assigned_at' => $assignedTo ? $now : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('ticket_activities')->insert([
            'ticket_id' => $ticketId,
            'user_id' => $adminId,
            'action' => 'customer_lifecycle_applied',
            'old_value' => json_encode([], JSON_THROW_ON_ERROR),
            'new_value' => json_encode(['lifecycle_action' => 'dismantle'], JSON_THROW_ON_ERROR),
            'description' => 'Approved dismantle E2E fixture',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $ticketId;
    }

    private function removePreviousFixtures(): void
    {
        $ticketIds = DB::table('support_tickets')
            ->where('code', 'like', 'TCK-E2E-%')
            ->pluck('id');

        if ($ticketIds->isEmpty()) {
            return;
        }

        $caseIds = DB::table('device_recovery_cases')
            ->whereIn('ticket_id', $ticketIds)
            ->pluck('id');
        if ($caseIds->isNotEmpty()) {
            DB::table('device_recovery_events')->whereIn('recovery_case_id', $caseIds)->delete();
            DB::table('device_recovery_items')->whereIn('recovery_case_id', $caseIds)->delete();
            DB::table('device_recovery_cases')->whereIn('id', $caseIds)->delete();
        }

        DB::table('ticket_comments')->whereIn('ticket_id', $ticketIds)->delete();
        DB::table('ticket_activities')->whereIn('ticket_id', $ticketIds)->delete();
        DB::table('support_tickets')->whereIn('id', $ticketIds)->delete();
    }
}
