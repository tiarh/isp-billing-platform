<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('psb_drafts')) {
            return;
        }

        Schema::table('psb_drafts', function (Blueprint $table): void {
            if (! $this->indexExists('psb_drafts', 'psb_drafts_panel_site_id_idx')) {
                $table->index(['site_profile_key', 'id'], 'psb_drafts_panel_site_id_idx');
            }
            if (! $this->indexExists('psb_drafts', 'psb_drafts_panel_site_status_id_idx')) {
                $table->index(['site_profile_key', 'status', 'id'], 'psb_drafts_panel_site_status_id_idx');
            }
            if (! $this->indexExists('psb_drafts', 'psb_drafts_panel_site_created_idx')) {
                $table->index(['site_profile_key', 'created_at'], 'psb_drafts_panel_site_created_idx');
            }
            if (! $this->indexExists('psb_drafts', 'psb_drafts_panel_status_id_idx')) {
                $table->index(['status', 'id'], 'psb_drafts_panel_status_id_idx');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('psb_drafts')) {
            return;
        }

        Schema::table('psb_drafts', function (Blueprint $table): void {
            foreach ([
                'psb_drafts_panel_site_id_idx',
                'psb_drafts_panel_site_status_id_idx',
                'psb_drafts_panel_site_created_idx',
                'psb_drafts_panel_status_id_idx',
            ] as $index) {
                if ($this->indexExists('psb_drafts', $index)) {
                    $table->dropIndex($index);
                }
            }
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        foreach (Schema::getIndexes($table) as $meta) {
            if (($meta['name'] ?? '') === $index) {
                return true;
            }
        }

        return false;
    }
};
