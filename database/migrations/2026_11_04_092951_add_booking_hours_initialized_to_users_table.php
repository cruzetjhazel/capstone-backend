<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('booking_hours_initialized')->default(false);
        });

        // Photographers who already have hours keep exactly what they have.
        DB::table('users')
            ->whereIn('id', DB::table('booking_hours')->select('user_id'))
            ->update(['booking_hours_initialized' => true]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('booking_hours_initialized');
        });
    }
};