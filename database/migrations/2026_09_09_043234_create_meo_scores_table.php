<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One day's MEO score for one store front.
 *
 * The score is a weighted average of four things the product already
 * measures, and breakdown holds what each of them contributed — a score
 * without its working is not something anyone can act on, and it is what the
 * improvement proposals are written from.
 *
 * A day is scored once and rescoring overwrites it, so the history is one row
 * per store front per day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meo_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            // 0.0 to 100.0, to one decimal place.
            $table->decimal('score', 5, 1);
            $table->json('breakdown');
            $table->dateTime('calculated_at');
            $table->timestamps();

            // A day is scored once; the sweep overwrites what it finds.
            $table->unique(['location_id', 'calculated_at']);
            $table->index(['organization_id', 'calculated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meo_scores');
    }
};
