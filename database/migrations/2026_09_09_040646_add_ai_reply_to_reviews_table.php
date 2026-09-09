<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The draft a model wrote for a review, kept beside the reply that was
 * actually sent.
 *
 * They are separate columns on purpose: `reply` is what Google holds, and a
 * draft waiting for approval must never be mistaken for it. The draft becomes
 * the reply only when it is approved and the call to Google succeeds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->text('ai_reply')->nullable()->after('reply');
            $table->string('ai_reply_status', 24)->nullable()->after('ai_reply');
            $table->string('ai_reply_model', 64)->nullable()->after('ai_reply_status');
            $table->dateTime('ai_reply_generated_at')->nullable()->after('ai_reply_model');
            $table->foreignId('ai_reply_approved_by_user_id')->nullable()->after('ai_reply_generated_at')
                ->constrained('users')->nullOnDelete();
            $table->dateTime('ai_reply_approved_at')->nullable()->after('ai_reply_approved_by_user_id');
            $table->string('ai_reply_error')->nullable()->after('ai_reply_approved_at');

            // The approval queue: drafts of one store front waiting on someone.
            $table->index(['location_id', 'ai_reply_status']);
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropForeign(['ai_reply_approved_by_user_id']);
            $table->dropIndex(['location_id', 'ai_reply_status']);
            $table->dropColumn([
                'ai_reply',
                'ai_reply_status',
                'ai_reply_model',
                'ai_reply_generated_at',
                'ai_reply_approved_by_user_id',
                'ai_reply_approved_at',
                'ai_reply_error',
            ]);
        });
    }
};
