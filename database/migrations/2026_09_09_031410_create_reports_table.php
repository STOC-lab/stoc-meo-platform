<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One generated monthly report per organization.
 *
 * A month is reported on once, so the period is unique per organization and a
 * re-run overwrites the file rather than adding a second row. The row is
 * created before the PDF exists and only moves forward through its statuses,
 * so it carries created_at alone and marks the end with generated_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            // The first day of the month reported on, which is what names the
            // file and what the list is ordered by.
            $table->date('period_start');
            $table->string('status', 16)->default('pending');
            // Relative to the reports disk: {organization}/{YYYY-MM}.pdf.
            $table->string('path')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->dateTime('generated_at')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['organization_id', 'period_start']);
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
