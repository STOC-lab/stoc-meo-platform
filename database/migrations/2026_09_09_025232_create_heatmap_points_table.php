<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One grid point of one heatmap: where the search was run from, and what
 * position the store front held there. A 5x5 run writes 25 rows and a 7x7 run
 * 49, all in one insert.
 *
 * Rows are written once and never updated — a re-check is a new run — so the
 * table carries created_at alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('heatmap_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('heatmap_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            // Null means the store front did not appear in the results seen
            // from this point, which is the reading the map is drawn to show.
            $table->unsignedSmallInteger('rank')->nullable();
            // Where the point sits in the grid, so the map can be drawn
            // without inferring the layout back out of the coordinates.
            $table->unsignedTinyInteger('row');
            $table->unsignedTinyInteger('col');
            $table->timestamp('created_at')->nullable();

            $table->unique(['heatmap_run_id', 'row', 'col']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('heatmap_points');
    }
};
