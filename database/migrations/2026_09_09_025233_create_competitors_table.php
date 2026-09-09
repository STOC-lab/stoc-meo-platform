<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A rival store front watched alongside one of the organization's own.
 *
 * How many a store may watch is an entitlement — competitor.limit — counted
 * from these rows rather than metered, so removing one gives the slot back.
 * The row is a plain record with nothing to edit but its name, so it carries
 * created_at alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competitors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // The rival's Google Business Profile place id where it is known.
            // Two stores may watch the same rival, so this is unique per store
            // front rather than globally.
            $table->string('gbp_place_id')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['location_id', 'gbp_place_id']);
            $table->index(['organization_id', 'location_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competitors');
    }
};
