<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A review left on a store front's Google Business Profile.
 *
 * Google owns the review; this table is a copy kept so the product can list,
 * search and report on them without calling out every time. The sync matches
 * on google_review_id, which is unique per store front, and updates the row it
 * finds rather than adding a second copy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->string('google_review_id');
            $table->string('author_name')->nullable();
            $table->string('author_photo_url', 1024)->nullable();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->text('comment')->nullable();
            $table->text('reply')->nullable();
            $table->dateTime('replied_at')->nullable();
            // When Google says the review was left, which is not when we first
            // saw it. The list is ordered by this.
            $table->dateTime('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['location_id', 'google_review_id']);
            $table->index(['organization_id', 'reviewed_at']);
            // The unanswered-reviews view.
            $table->index(['location_id', 'replied_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
