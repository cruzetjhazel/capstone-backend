<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('booking_budget_requests', 'client_id')) {
            Schema::table('booking_budget_requests', function (Blueprint $table) {
                $table->foreignId('client_id')
                    ->nullable()
                    ->after('booking_id')
                    ->constrained('users')
                    ->nullOnDelete();
            });
        }

        if (!Schema::hasColumn('booking_budget_requests', 'photographer_id')) {
            Schema::table('booking_budget_requests', function (Blueprint $table) {
                $table->foreignId('photographer_id')
                    ->nullable()
                    ->after('client_id')
                    ->constrained('users')
                    ->nullOnDelete();
            });
        }

        // Backfill existing rows from their related bookings.
        DB::statement('
            UPDATE booking_budget_requests AS bbr
            LEFT JOIN bookings AS b ON b.id = bbr.booking_id
            SET
                bbr.client_id = COALESCE(bbr.client_id, b.client_id),
                bbr.photographer_id = COALESCE(
                    bbr.photographer_id,
                    b.photographer_id
                )
        ');
    }

    public function down(): void
    {
        // Existing columns may belong to an earlier migration.
        // Do not drop them automatically.
    }
};
