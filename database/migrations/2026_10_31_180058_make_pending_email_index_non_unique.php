<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A pending address is not owned by anyone until verified, so it must not be unique:
 * with a unique index one account could "park" an address as pending and block its real
 * owner from ever using it. Ownership is enforced when it matters: at request time and
 * again inside the verification transaction (users.email is checked case-insensitively).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['pending_email']);
            $table->index('pending_email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['pending_email']);
            $table->unique('pending_email');
        });
    }
};