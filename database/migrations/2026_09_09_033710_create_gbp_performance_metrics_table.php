<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One day's value of one Business Profile performance metric for one store
 * front — impressions, direction requests, call clicks and the rest.
 *
 * Google serves whole closed days and revises them for a while afterwards, so
 * a day is unique per metric and per store front and the sync overwrites what
 * it already has rather than adding to it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gbp_performance_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            // Google's own name for the metric, e.g. CALL_CLICKS. Kept as
            // given so a metric added later needs no migration.
            $table->string('metric', 64);
            $table->date('date');
            $table->unsignedBigInteger('value')->default(0);
            $table->timestamps();

            $table->unique(['location_id', 'metric', 'date']);
            $table->index(['organization_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gbp_performance_metrics');
    }
};
