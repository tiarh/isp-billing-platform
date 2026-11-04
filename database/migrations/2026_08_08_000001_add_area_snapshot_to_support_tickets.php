<?php

use App\Models\SupportTicket;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('support_tickets', 'area_id')) {
            Schema::table('support_tickets', function (Blueprint $table) {
                $table->foreignId('area_id')->nullable()->after('customer_id')->constrained('areas')->nullOnDelete();
                $table->index(['area_id', 'status']);
            });
        }

        foreach (SupportTicket::query()->whereNull('area_id')->whereNotNull('customer_id')->with('customer:id,area_id')->cursor() as $ticket) {
            if ($ticket->customer?->area_id) {
                $ticket->updateQuietly(['area_id' => $ticket->customer->area_id]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('support_tickets', 'area_id')) {
            Schema::table('support_tickets', function (Blueprint $table) {
                $table->dropForeign(['area_id']);
                $table->dropIndex(['area_id', 'status']);
                $table->dropColumn('area_id');
            });
        }
    }
};
