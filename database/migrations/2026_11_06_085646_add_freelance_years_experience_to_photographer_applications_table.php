<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
        public function up(): void
    {
        Schema::table('photographer_applications', function (Blueprint $table) {
            $table->unsignedSmallInteger('freelance_years_experience')->nullable()->after('years_active');
        });
    }

    public function down(): void
    {
        Schema::table('photographer_applications', function (Blueprint $table) {
            $table->dropColumn('freelance_years_experience');
        });
    }
};
