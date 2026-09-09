<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Something a model suggested the store front do.
 *
 * A proposal is kept after it is acted on or turned down rather than deleted,
 * so the weekly sweep can see what has already been suggested and not repeat
 * itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('improvement_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->string('category', 32);
            $table->string('title');
            $table->text('content');
            $table->string('priority', 16)->default('medium');
            $table->string('status', 16)->default('new');
            // Which score prompted it, so a proposal can be read beside the
            // number it came from.
            $table->decimal('score_at_generation', 5, 1)->nullable();
            $table->timestamps();

            // The open list of one store front, most urgent first.
            $table->index(['location_id', 'status', 'priority']);
            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('improvement_proposals');
    }
};
