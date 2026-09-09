<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Something the organization should look at — for now, a keyword whose rank
 * fell sharply overnight.
 *
 * The keyword is nullable because later alert types will not all be about one:
 * a review left unanswered or a post that failed to publish belongs here too,
 * and payload carries whatever that type needs to render.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('keyword_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type', 64);
            $table->json('payload')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamp('created_at')->nullable();

            // The unread badge counts one organization's open alerts.
            $table->index(['organization_id', 'is_read', 'created_at']);
            $table->index(['keyword_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alerts');
    }
};
