<?php

use App\Models\WalkInClient;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            // A manual booking is recorded for a walk-in client, who has no user account.
            $table->unsignedBigInteger('client_id')->nullable()->change();

            $table->foreignId('walk_in_client_id')
                ->nullable()
                ->after('client_id')
                ->constrained((new WalkInClient)->getTable())
                ->restrictOnDelete();

            $table->boolean('is_manual')->default(false)->after('walk_in_client_id');
            $table->decimal('manual_amount_paid', 10, 2)->default(0)->after('is_manual');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('walk_in_client_id');
            $table->dropColumn(['is_manual', 'manual_amount_paid']);
            // client_id is left nullable on purpose: rolling it back to NOT NULL would fail once manual rows exist.
        });
    }
};