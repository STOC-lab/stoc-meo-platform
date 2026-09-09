<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A post published to a store front's Google Business Profile.
 *
 * The row is written before the call to Google, so a post that fails to
 * publish is still visible with the reason rather than vanishing. gbp_post_id
 * is what Google called it, and is only there once it has actually published.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gbp_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->text('content');
            $table->string('media_url', 1024)->nullable();
            $table->string('cta_type', 32)->nullable();
            $table->string('cta_url', 1024)->nullable();
            $table->string('status', 16)->default('draft');
            $table->dateTime('published_at')->nullable();
            $table->string('gbp_post_id')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamps();

            $table->index(['location_id', 'created_at']);
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gbp_posts');
    }
};
