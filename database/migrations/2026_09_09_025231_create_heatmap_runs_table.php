<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One heatmap: a keyword checked from every point of a grid laid over the
 * store front's neighbourhood.
 *
 * A run is created before the work happens and is never edited afterwards
 * beyond its status and completed_at, so it carries created_at alone;
 * completed_at is what says when the grid was finished.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('heatmap_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->foreignId('keyword_id')->constrained()->cascadeOnDelete();
            $table->enum('grid_size', ['5x5', '7x7']);
            $table->string('status', 16)->default('pending');
            // When the run was asked for, which is not when it was picked up:
            // a queued run waits for a worker, and a scheduled one waits for
            // its slot.
            $table->dateTime('scheduled_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            // Why a failed run failed, for the history list to show.
            $table->string('failure_reason')->nullable();
            $table->timestamp('created_at')->nullable();

            // The history list reads one store front's runs newest first.
            $table->index(['location_id', 'created_at']);
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('heatmap_runs');
    }
};
