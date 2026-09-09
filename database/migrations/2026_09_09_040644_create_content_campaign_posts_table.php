<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One campaign's post for one channel.
 *
 * Each channel is published by its own job, so the row carries its own status,
 * its own retry count and its own error: Instagram failing leaves the Business
 * Profile post alone, which is what design v1.3 §22 asks for.
 *
 * idempotency_key is generated when the row is created and sent to the channel
 * where the channel supports one, so a retry after an ambiguous timeout cannot
 * publish the same thing twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_campaign_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('content_campaigns')->cascadeOnDelete();
            // Denormalised from the campaign so the tenant scope and the
            // per-organization indexes work without a join.
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->enum('channel', ['instagram', 'gbp', 'wordpress']);
            $table->enum('status', [
                'pending',
                'ai_generating',
                'awaiting_approval',
                'approved',
                'publishing',
                'published',
                'failed',
                'cancelled',
            ])->default('pending');
            $table->text('ai_prompt')->nullable();
            $table->longText('ai_content')->nullable();
            $table->json('ai_hashtags')->nullable();
            $table->string('platform_post_id')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->unsignedTinyInteger('retry_count')->default(0);
            $table->unsignedTinyInteger('max_retries')->default(3);
            $table->text('last_error')->nullable();
            $table->char('idempotency_key', 36)->nullable();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->timestamps();

            // A campaign posts to a channel once.
            $table->unique(['campaign_id', 'channel']);
            $table->unique('idempotency_key');
            $table->index(['organization_id', 'status']);
            // The approval queue.
            $table->index(['status', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_campaign_posts');
    }
};
