<?php

namespace Tests\Feature;

use App\Models\Keyword;
use App\Models\RankingResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The rank history is the table that grows without bound, so its monthly
 * partitioning is part of the schema's contract rather than an optimisation
 * detail: reporting prunes to a month, and retiring one is a DROP PARTITION.
 */
class RankingResultPartitionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Partitioning is only set up on MySQL.');
        }
    }

    public function test_the_history_is_partitioned_by_month(): void
    {
        $partitions = collect($this->partitions())->pluck('PARTITION_NAME');

        $this->assertContains('p202601', $partitions);
        $this->assertContains('p202609', $partitions);
        $this->assertContains('p202712', $partitions);
        $this->assertContains('p_future', $partitions, 'Later months must still have somewhere to land.');
        $this->assertSame(25, $partitions->count(), 'Two years of months, plus the catch-all.');
    }

    public function test_a_check_lands_in_the_partition_for_its_month(): void
    {
        $keyword = Keyword::factory()->create();

        RankingResult::factory()->forKeyword($keyword)->create(['checked_at' => '2026-09-15 02:00:00']);
        RankingResult::factory()->forKeyword($keyword)->create(['checked_at' => '2026-10-01 02:00:00']);

        $this->assertSame(1, $this->countIn('p202609'));
        $this->assertSame(1, $this->countIn('p202610'));
        $this->assertSame(0, $this->countIn('p202611'));
    }

    public function test_the_primary_key_carries_the_partitioning_column(): void
    {
        $columns = collect(DB::select(
            'SELECT COLUMN_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
             ORDER BY SEQ_IN_INDEX',
            ['ranking_results', 'PRIMARY'],
        ))->pluck('COLUMN_NAME')->all();

        $this->assertSame(['id', 'checked_at'], $columns);
    }

    /**
     * @return array<int, object>
     */
    protected function partitions(): array
    {
        return DB::select(
            'SELECT PARTITION_NAME FROM information_schema.PARTITIONS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
             ORDER BY PARTITION_ORDINAL_POSITION',
            ['ranking_results'],
        );
    }

    protected function countIn(string $partition): int
    {
        return (int) DB::selectOne("SELECT COUNT(*) AS total FROM ranking_results PARTITION ({$partition})")->total;
    }
}
