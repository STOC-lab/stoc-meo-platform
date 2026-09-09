<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A content campaign: one theme and one source image turned into posts across
 * Instagram, a Business Profile and a blog, as set out in STOC MEO SYSTEM
 * DESIGN v1.3 §22.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // What the posts should be about, in the operator's own words. It
            // is the heart of the prompt, so it is free text rather than a
            // list to choose from.
            $table->text('theme')->nullable();
            $table->string('source_image_path', 1024)->nullable();
            $table->enum('campaign_type', ['manual', 'scheduled', 'recurring'])->default('manual');
            $table->dateTime('scheduled_at')->nullable();
            $table->enum('status', ['draft', 'active', 'completed', 'cancelled'])->default('draft');
            // Who started it. Kept even if they later leave the organization,
            // so the record of who asked for a post does not disappear.
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['location_id', 'status']);
            $table->index(['organization_id', 'created_at']);
            // The scheduler looks for campaigns whose moment has come.
            $table->index(['status', 'campaign_type', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_campaigns');
    }
};
