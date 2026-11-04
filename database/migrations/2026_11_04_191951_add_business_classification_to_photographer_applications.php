<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('photographer_applications', function (Blueprint $table) {
            $table->boolean('is_freelancer')->default(false)->after('photographer_type');
            $table->boolean('is_studio_owner')->default(false)->after('is_freelancer');
        });

        // Existing accounts keep their classification.
        DB::table('photographer_applications')->where('photographer_type', 'freelancer')->update(['is_freelancer' => true]);
        DB::table('photographer_applications')->where('photographer_type', 'studio')->update(['is_studio_owner' => true]);
    }

    public function down(): void
    {
        Schema::table('photographer_applications', function (Blueprint $table) {
            $table->dropColumn(['is_freelancer', 'is_studio_owner']);
        });
    }
};