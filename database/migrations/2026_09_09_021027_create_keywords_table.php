<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('keywords', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->string('keyword');
            // Tracking can be paused without losing the history behind it.
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['location_id', 'keyword']);
            // The daily sweep reads every active keyword, tenant by tenant.
            $table->index(['organization_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('keywords');
    }
};
