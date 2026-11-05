<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('platform_settings', 'key')) {
                $table->string('key')->unique()->after('id');
            }
            if (! Schema::hasColumn('platform_settings', 'value')) {
                $table->text('value')->nullable()->after('key');
            }
        });
    }

    public function down(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->dropColumn(['key', 'value']);
        });
    }
};
