<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One AI-written analysis of a store front over a period.
 *
 * content is JSON rather than text: the analysis has parts — a summary, what
 * moved, what to watch — and storing them separately means the UI can show one
 * without parsing prose.
 *
 * A period is analysed once per type, so re-running overwrites rather than
 * adding a second reading of the same days.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16);
            $table->json('content');
            $table->date('period_start');
            $table->date('period_end');
            $table->string('model', 64)->nullable();
            $table->timestamps();

            $table->unique(['location_id', 'type', 'period_start']);
            $table->index(['organization_id', 'type', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analyses');
    }
};
