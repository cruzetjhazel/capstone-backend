<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ONLY NEEDED if users.phone_number has no unique index yet. Skip it (delete this file)
 * if you already have one. Existing rows are in mixed formats (+639…, 09…), so this index
 * only catches exact-string duplicates; the real cross-format check is
 * ChangeContactNumberAction (PhilippinePhone::variants).
 */
return new class extends Migration
{
    public function up(): void
    {
        $dupes = DB::table('users')
            ->select('phone_number')
            ->whereNotNull('phone_number')
            ->where('phone_number', '!=', '')
            ->groupBy('phone_number')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('phone_number');

        if ($dupes->isNotEmpty()) {
            throw new RuntimeException('Resolve duplicate phone numbers before adding the unique index: '.$dupes->implode(', '));
        }

        Schema::table('users', function (Blueprint $table) {
            $table->unique('phone_number');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['phone_number']);
        });
    }
};