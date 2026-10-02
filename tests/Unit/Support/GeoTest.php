<?php

namespace Tests\Unit\Support;

use App\Support\Geo;
use PHPUnit\Framework\TestCase;

class GeoTest extends TestCase
{
    public function test_the_distance_to_the_same_point_is_zero()
    {
        $this->assertSame(0.0, Geo::distanceKm(3.1390, 101.6869, 3.1390, 101.6869));
    }

    public function test_it_measures_great_circle_distances()
    {
        // KLCC to Petaling Jaya SS2 is about 10.8 km as the crow flies.
        $this->assertEqualsWithDelta(10.8, Geo::distanceKm(3.1579, 101.7116, 3.1185, 101.6225), 0.3);

        // Kuala Lumpur to George Town, Penang is roughly 290 km.
        $this->assertEqualsWithDelta(290.0, Geo::distanceKm(3.1390, 101.6869, 5.4141, 100.3288), 10.0);
    }

    public function test_distance_is_symmetric()
    {
        $this->assertEqualsWithDelta(
            Geo::distanceKm(3.0763, 101.5883, 3.0850, 101.5390),
            Geo::distanceKm(3.0850, 101.5390, 3.0763, 101.5883),
            1e-9,
        );
    }
}
