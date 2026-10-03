<?php

namespace Tests\Unit\Support;

use App\Support\DropOffTiming;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DropOffTimingTest extends TestCase
{
    /**
     * @return array<string, array{list<float>, float, float}>
     */
    public static function percentiles(): array
    {
        return [
            'median of an odd count is the middle value' => [[1.0, 2.0, 9.0], 50, 2.0],
            'median of an even count is halfway between the middle two' => [[1.0, 2.0, 3.0, 4.0], 50, 2.5],
            '90th percentile interpolates between ranks' => [[1.0, 2.0, 3.0, 4.0], 90, 3.7],
            '95th percentile of ten values' => [[1.0, 2.0, 3.0, 4.0, 5.0, 6.0, 7.0, 8.0, 9.0, 10.0], 95, 9.55],
            'a single value is every percentile' => [[2.5], 95, 2.5],
            'the 0th and 100th percentiles are the extremes' => [[0.5, 3.0, 8.0], 100, 8.0],
            'equal values' => [[2.0, 2.0, 2.0, 2.0], 90, 2.0],
        ];
    }

    /**
     * @param  list<float>  $sorted
     */
    #[DataProvider('percentiles')]
    public function test_percentiles_interpolate_between_the_nearest_ranks(array $sorted, float $p, float $expected)
    {
        $this->assertEqualsWithDelta($expected, DropOffTiming::percentile($sorted, $p), 1e-9);
    }

    public function test_there_is_no_percentile_without_values()
    {
        $this->assertNull(DropOffTiming::percentile([], 50));
    }

    public function test_the_suggestion_compares_the_95th_percentile_with_the_limit()
    {
        $this->assertSame('95% drop off within 2.4 days, inside the 7-day limit.', DropOffTiming::suggestion(2.44, 7));
        $this->assertSame('95% drop off within 7.0 days, inside the 7-day limit.', DropOffTiming::suggestion(7.0, 7));
        $this->assertSame(
            '95% drop off within 9.2 days, longer than the 7-day limit. Consider allowing 10 days.',
            DropOffTiming::suggestion(9.2, 7),
        );
    }

    public function test_the_suggestion_rounds_the_95th_percentile_before_comparing_it()
    {
        // Shown as 7.0, so it reads as inside the limit, not over it.
        $this->assertSame('95% drop off within 7.0 days, inside the 7-day limit.', DropOffTiming::suggestion(7.04, 7));
        $this->assertSame(
            '95% drop off within 7.1 days, longer than the 7-day limit. Consider allowing 8 days.',
            DropOffTiming::suggestion(7.06, 7),
        );
    }

    public function test_the_suggested_limit_never_exceeds_the_largest_allowed()
    {
        $this->assertStringEndsWith('Consider allowing 60 days.', DropOffTiming::suggestion(75.3, 30));
    }
}
