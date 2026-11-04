<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('olts') && Schema::hasColumn('olts', 'snmp_community')) {
            DB::table('olts')
                ->select(['id', 'snmp_community'])
                ->whereNotNull('snmp_community')
                ->orderBy('id')
                ->chunkById(100, function ($rows): void {
                    foreach ($rows as $row) {
                        $encrypted = $this->encryptScalar($row->snmp_community);
                        if ($encrypted !== $row->snmp_community) {
                            DB::table('olts')->where('id', $row->id)->update([
                                'snmp_community' => $encrypted,
                            ]);
                        }
                    }
                });
        }

        if (! Schema::hasTable('olt_operation_logs')) {
            return;
        }

        DB::table('olt_operation_logs')
            ->select(['id', 'payload', 'result', 'error'])
            ->orderBy('id')
            ->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    $updates = [];

                    foreach (['payload', 'result'] as $column) {
                        if ($row->{$column} !== null && $row->{$column} !== '') {
                            $encrypted = $this->encryptJson($row->{$column});
                            if ($encrypted !== $row->{$column}) {
                                $updates[$column] = $encrypted;
                            }
                        }
                    }

                    if ($row->error !== null && $row->error !== '') {
                        $encrypted = $this->encryptScalar($row->error);
                        if ($encrypted !== $row->error) {
                            $updates['error'] = $encrypted;
                        }
                    }

                    if ($updates !== []) {
                        DB::table('olt_operation_logs')->where('id', $row->id)->update($updates);
                    }
                }
            });
    }

    public function down(): void
    {
        // Security rollback must not restore device credentials or raw output.
    }

    private function encryptScalar(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }

        try {
            Crypt::decryptString((string) $value);

            return $value;
        } catch (Throwable) {
            return Crypt::encryptString((string) $value);
        }
    }

    private function encryptJson(mixed $value): string
    {
        $stored = (string) $value;
        $decoded = json_decode($stored, true);
        $ciphertext = is_string($decoded) ? $decoded : $stored;

        try {
            Crypt::decryptString($ciphertext);

            return $stored;
        } catch (Throwable) {
            $json = json_encode($decoded ?? $value, JSON_THROW_ON_ERROR);

            return json_encode(Crypt::encryptString($json), JSON_THROW_ON_ERROR);
        }
    }
};
