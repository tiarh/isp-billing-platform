<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Data contoh untuk screenshot FAQ / Panduan (e2e/faq-screenshots).
 * Semua nama, nomor, dan alamat fiktif. Idempoten: aman dijalankan berulang.
 * Hanya untuk sandbox CI (APP_ENV=testing), tidak pernah untuk produksi.
 */
class FaqScreenshotSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('testing')) {
            throw new RuntimeException('FaqScreenshotSeeder hanya boleh dijalankan pada APP_ENV=testing.');
        }

        $password = (string) env('E2E_PASSWORD');
        if ($password === '') {
            throw new RuntimeException('E2E_PASSWORD wajib tersedia untuk fixture screenshot FAQ.');
        }

        DB::transaction(function () use ($password): void {
            $now = now();
            $passwordHash = Hash::make($password);

            $adminId = $this->upsertUser('admin@local', 'E2E Admin', 'superadmin', $passwordHash, $now);
            $technicianId = $this->upsertUser('e2e-technician@example.test', 'E2E Technician', 'teknisi', $passwordHash, $now);

            DB::table('areas')->updateOrInsert(
                ['code' => 'FAQ-CONTOH'],
                ['name' => 'Area Contoh', 'created_at' => $now, 'updated_at' => $now],
            );
            $areaId = (int) DB::table('areas')->where('code', 'FAQ-CONTOH')->value('id');

            DB::table('packages')->updateOrInsert(
                ['code' => 'FAQ-20M'],
                [
                    'name' => 'Paket 20 Mbps',
                    'description' => 'Data contoh untuk screenshot FAQ',
                    'price' => 150000,
                    'speed_mbps' => 20,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
            $packageId = (int) DB::table('packages')->where('code', 'FAQ-20M')->value('id');

            $customers = [
                ['FAQ-001', 'Budi Contoh', 'active', 'Jl. Mawar No. 1'],
                ['FAQ-002', 'Siti Rahayu', 'isolated', 'Jl. Melati No. 12'],
                ['FAQ-003', 'Agus Pratama', 'active', 'Jl. Kenanga No. 7'],
            ];
            $customerIds = [];
            foreach ($customers as $index => [$code, $name, $status, $address]) {
                DB::table('customers')->updateOrInsert(
                    ['code' => $code],
                    [
                        'name' => $name,
                        'email' => Str::slug($name).'@example.test',
                        'phone' => '08000000'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
                        'address' => $address,
                        'area_id' => $areaId,
                        'package_id' => $packageId,
                        'pppoe_user' => 'faq-'.Str::slug($name),
                        'status' => $status === 'overdue' ? 'unpaid' : $status,
                        'join_date' => $now->copy()->subMonths(6)->toDateString(),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                );
                $customerIds[$code] = (int) DB::table('customers')->where('code', $code)->value('id');
            }

            DB::table('support_tickets')->where('code', 'like', 'TCK-FAQ-%')->delete();
            DB::table('invoices')->where('code', 'like', 'INV-FAQ-%')->delete();

            $period = $now->copy()->startOfMonth();
            $invoices = [
                ['INV-FAQ-001', 'FAQ-001', 'paid', $period->copy()->day(20)],
                ['INV-FAQ-002', 'FAQ-002', 'overdue', $period->copy()->subMonth()->day(20)],
                ['INV-FAQ-003', 'FAQ-003', 'unpaid', $period->copy()->day(20)],
            ];
            foreach ($invoices as [$code, $customerCode, $status, $dueDate]) {
                $invoiceId = (int) DB::table('invoices')->insertGetId([
                    'uuid' => (string) Str::uuid(),
                    'code' => $code,
                    'customer_id' => $customerIds[$customerCode],
                    'period' => $status === 'overdue' ? $period->copy()->subMonth()->toDateString() : $period->toDateString(),
                    'amount' => 150000,
                    'status' => $status === 'overdue' ? 'unpaid' : $status,
                    'due_date' => $dueDate->toDateString(),
                    'generated_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                if ($status === 'paid') {
                    DB::table('transactions')->insert([
                        'reference' => 'TRX-FAQ-001',
                        'invoice_id' => $invoiceId,
                        'admin_id' => $adminId,
                        'amount' => 150000,
                        'channel' => 'manual',
                        'method' => 'cash',
                        'status' => 'verified',
                        'paid_at' => $now->copy()->subDays(2),
                        'notes' => 'Pembayaran tunai di kantor (data contoh)',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }

            $tickets = [
                ['TCK-FAQ-001', 'FAQ-001', 'incident', 'high', 'assigned', $technicianId, 'Internet mati sejak pagi', 'Lampu LOS merah, sudah restart ONU tetap mati.'],
                ['TCK-FAQ-002', 'FAQ-003', 'billing', 'normal', 'open', null, 'Tanya rincian tagihan bulan ini', 'Pelanggan minta rincian tagihan dikirim via WA.'],
                ['TCK-FAQ-003', 'FAQ-002', 'incident', 'normal', 'in_progress', $technicianId, 'Koneksi lambat malam hari', 'Speedtest 2 Mbps dari paket 20 Mbps setiap pukul 19-22.'],
            ];
            foreach ($tickets as [$code, $customerCode, $type, $priority, $status, $assignedTo, $subject, $description]) {
                $customer = DB::table('customers')->where('id', $customerIds[$customerCode])->first();
                DB::table('support_tickets')->insert([
                    'code' => $code,
                    'report_id' => str_replace('TCK', 'RPT', $code),
                    'customer_id' => $customer->id,
                    'customer_name' => $customer->name,
                    'customer_phone' => $customer->phone,
                    'assigned_to' => $assignedTo,
                    'created_by' => $adminId,
                    'area_id' => $areaId,
                    'type' => $type,
                    'priority' => $priority,
                    'status' => $status === 'overdue' ? 'unpaid' : $status,
                    'subject' => $subject,
                    'description' => $description,
                    'gangguan' => $subject,
                    'due_at' => $now->copy()->addDay(),
                    'assigned_at' => $assignedTo ? $now : null,
                    'created_at' => $now->copy()->subHours(3),
                    'updated_at' => $now,
                ]);
            }
        });
    }

    private function upsertUser(string $email, string $name, string $role, string $passwordHash, mixed $now): int
    {
        DB::table('users')->updateOrInsert(
            ['email' => $email],
            [
                'name' => $name,
                'password' => $passwordHash,
                'role' => $role,
                'email_verified_at' => $now,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );

        return (int) DB::table('users')->where('email', $email)->value('id');
    }
}
