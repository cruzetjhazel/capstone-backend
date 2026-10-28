<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('active_reference_number')->nullable()->after('reference_number');
        });

        // Backfill: the first non-rejected payment per reference keeps it; later duplicates stay null.
        $seen = [];
        foreach (DB::table('payments')->whereNotNull('reference_number')->orderBy('id')->get() as $p) {
            if ($p->matching_status === 'rejected' || isset($seen[$p->reference_number])) {
                continue;
            }
            $seen[$p->reference_number] = true;
            DB::table('payments')->where('id', $p->id)->update(['active_reference_number' => $p->reference_number]);
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->unique('active_reference_number');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['active_reference_number']);
            $table->dropColumn('active_reference_number');
        });
    }
};