<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rank history, one row per keyword per check. This is the table that grows
 * without bound, so it is partitioned by month: reporting reads a date range
 * and touches one or two partitions, and retiring an old month is a DROP
 * PARTITION rather than a long DELETE.
 *
 * MySQL asks for two things in exchange. Every unique key has to contain the
 * partitioning column, so the primary key is (id, checked_at) rather than id
 * alone; and partitioned tables cannot carry foreign keys, so the references
 * to organizations, locations and keywords are plain indexed columns, cleaned
 * up by the application rather than by the database.
 */
return new class extends Migration
{
    /**
     * The first and last month to give a partition of its own. Anything older
     * lands in the first partition and anything newer in the catch-all, so no
     * insert can ever fail for want of a partition.
     */
    protected const FIRST_MONTH = '2026-01';

    protected const LAST_MONTH = '2027-12';

    public function up(): void
    {
        Schema::create('ranking_results', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('location_id');
            $table->unsignedBigInteger('keyword_id');
            // Null means the location did not appear in the results at all.
            $table->unsignedSmallInteger('rank')->nullable();
            $table->string('search_url', 2048)->nullable();
            // A DATETIME, not a TIMESTAMP: RANGE COLUMNS partitioning accepts
            // the former and rejects the latter.
            $table->dateTime('checked_at');
            $table->string('provider', 32);
            $table->timestamp('created_at')->nullable();

            $table->index(['organization_id', 'keyword_id', 'checked_at'], 'ranking_results_history_index');
            $table->index(['location_id', 'checked_at']);
        });

        if (! $this->supportsPartitioning()) {
            return;
        }

        DB::statement('ALTER TABLE ranking_results DROP PRIMARY KEY, ADD PRIMARY KEY (id, checked_at)');
        DB::statement('ALTER TABLE ranking_results PARTITION BY RANGE COLUMNS (checked_at) ('.$this->partitions().')');
    }

    public function down(): void
    {
        Schema::dropIfExists('ranking_results');
    }

    /**
     * One partition per month, then a catch-all for everything beyond the last
     * one. New months are carved out of the catch-all with REORGANIZE
     * PARTITION when the window runs short.
     */
    protected function partitions(): string
    {
        $month = Carbon::createFromFormat('Y-m-d', self::FIRST_MONTH.'-01')->startOfMonth();
        $last = Carbon::createFromFormat('Y-m-d', self::LAST_MONTH.'-01')->startOfMonth();
        $definitions = [];

        while ($month->lessThanOrEqualTo($last)) {
            $boundary = $month->copy()->addMonth();

            $definitions[] = sprintf(
                "PARTITION p%s VALUES LESS THAN ('%s')",
                $month->format('Ym'),
                $boundary->format('Y-m-d H:i:s'),
            );

            $month = $boundary;
        }

        $definitions[] = 'PARTITION p_future VALUES LESS THAN (MAXVALUE)';

        return implode(', ', $definitions);
    }

    protected function supportsPartitioning(): bool
    {
        return DB::connection()->getDriverName() === 'mysql';
    }
};
