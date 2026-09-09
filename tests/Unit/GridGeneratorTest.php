<?php

namespace Tests\Unit;

use App\Enums\HeatmapGridSize;
use App\Services\Heatmap\GridGenerator;
use App\Services\Heatmap\GridPoint;
use App\Services\Ranking\GeoPoint;
use Tests\TestCase;

class GridGeneratorTest extends TestCase
{
    protected GridGenerator $generator;

    /**
     * Shibuya station, near enough.
     */
    protected GeoPoint $centre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->generator = new GridGenerator;
        $this->centre = new GeoPoint(35.6580, 139.7016);
    }

    public function test_a_five_by_five_grid_has_twenty_five_points(): void
    {
        $points = $this->generator->generate($this->centre, HeatmapGridSize::Grid5x5);

        $this->assertCount(25, $points);
        $this->assertContainsOnlyInstancesOf(GridPoint::class, $points);
    }

    public function test_a_seven_by_seven_grid_has_forty_nine_points(): void
    {
        $this->assertCount(49, $this->generator->generate($this->centre, HeatmapGridSize::Grid7x7));
    }

    public function test_every_row_and_column_of_the_grid_is_filled_exactly_once(): void
    {
        $points = $this->generator->generate($this->centre, HeatmapGridSize::Grid7x7);

        $cells = array_map(fn (GridPoint $point) => "{$point->row}:{$point->col}", $points);

        $this->assertCount(49, array_unique($cells));
        $this->assertSame(range(0, 6), array_values(array_unique(
            array_map(fn (GridPoint $point) => $point->row, $points)
        )));
    }

    public function test_the_middle_point_sits_on_the_store_front(): void
    {
        $points = $this->generator->generate($this->centre, HeatmapGridSize::Grid5x5);

        $middle = $this->pointAt($points, 2, 2);

        $this->assertEqualsWithDelta($this->centre->latitude, $middle->coordinate->latitude, 1e-9);
        $this->assertEqualsWithDelta($this->centre->longitude, $middle->coordinate->longitude, 1e-9);
    }

    public function test_rows_run_north_to_south_and_columns_west_to_east(): void
    {
        $points = $this->generator->generate($this->centre, HeatmapGridSize::Grid5x5);

        $north = $this->pointAt($points, 0, 2);
        $south = $this->pointAt($points, 4, 2);
        $west = $this->pointAt($points, 2, 0);
        $east = $this->pointAt($points, 2, 4);

        $this->assertGreaterThan($south->coordinate->latitude, $north->coordinate->latitude);
        $this->assertLessThan($east->coordinate->longitude, $west->coordinate->longitude);
    }

    public function test_the_grid_spans_the_distance_the_size_asks_for(): void
    {
        $points = $this->generator->generate($this->centre, HeatmapGridSize::Grid5x5);

        // Four gaps of one kilometre between the outermost rows.
        $north = $this->pointAt($points, 0, 2)->coordinate;
        $south = $this->pointAt($points, 4, 2)->coordinate;

        $this->assertEqualsWithDelta(
            4.0,
            ($north->latitude - $south->latitude) * 110.574,
            0.01,
        );
    }

    public function test_longitude_spacing_widens_with_latitude(): void
    {
        $tokyo = $this->generator->generate(new GeoPoint(35.6580, 139.7016), HeatmapGridSize::Grid5x5);
        $equator = $this->generator->generate(new GeoPoint(0.0, 139.7016), HeatmapGridSize::Grid5x5);

        // A kilometre east is more degrees of longitude further from the
        // equator, since the meridians have converged.
        $this->assertGreaterThan(
            $this->pointAt($equator, 2, 4)->coordinate->longitude - 139.7016,
            $this->pointAt($tokyo, 2, 4)->coordinate->longitude - 139.7016,
        );
    }

    public function test_a_coordinate_is_formatted_the_way_the_provider_expects_it(): void
    {
        $this->assertSame(
            '35.6580000,139.7016000,14',
            (new GeoPoint(35.658, 139.7016))->toCoordinateString(),
        );
    }

    /**
     * @param  array<int, GridPoint>  $points
     */
    protected function pointAt(array $points, int $row, int $col): GridPoint
    {
        foreach ($points as $point) {
            if ($point->row === $row && $point->col === $col) {
                return $point;
            }
        }

        $this->fail("The grid has no point at row {$row}, column {$col}.");
    }
}
