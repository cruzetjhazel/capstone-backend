<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_item_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('photographer_application_id')
                ->constrained('photographer_applications')->cascadeOnDelete();
            // government_id | selfie_with_id | business_permit | additional_document | portfolio_image
            $table->string('item_type', 32);
            // single docs: same as item_type; additional docs: hash of the path; portfolio: image id
            $table->string('item_key', 64);
            // the exact file that was reviewed; if the current file differs, the review is stale
            $table->string('file_path', 500)->nullable();
            $table->string('status', 20)->default('pending'); // pending | reviewed | needs_revision
            $table->text('feedback')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['photographer_application_id', 'item_type', 'item_key'], 'app_item_reviews_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_item_reviews');
    }
};