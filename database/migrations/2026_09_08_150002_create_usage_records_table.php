<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('usage_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            // Billing period the counter belongs to, stored as the first day of
            // the period so counters reset simply by moving to the next one.
            $table->date('period_start');
            $table->unsignedInteger('used')->default(0);
            $table->timestamps();

            $table->unique(['organization_id', 'key', 'period_start']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('usage_records');
    }
};
