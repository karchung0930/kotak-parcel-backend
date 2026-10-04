<?php

namespace Tests\Unit\Support\RateSheets;

use App\Enums\MalaysianState;
use App\Support\RateSheets\ZoneMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ZoneMatcherTest extends TestCase
{
    /**
     * @return array<string, array{string, string|null}>
     */
    public static function names(): array
    {
        return [
            'the code' => ['sabah-labuan', 'sabah-labuan'],
            'the name in any case' => ['SABAH & LABUAN', 'sabah-labuan'],
            'a state' => ['Labuan', 'sabah-labuan'],
            'a state by its label' => ['W.P. Kuala Lumpur', 'peninsular-malaysia'],
            'west' => ['West', 'peninsular-malaysia'],
            'semenanjung' => ['Semenanjung', 'peninsular-malaysia'],
            'part of the name' => ['Peninsular', 'peninsular-malaysia'],
            'a close spelling' => ['Sarawk', 'sarawak'],
            // Sabah and Sarawak are in different zones, so "East" is not one zone.
            'east, split in two' => ['East', null],
            'an old name for the peninsula' => ['Malaya', 'peninsular-malaysia'],
            'a stranger' => ['Singapore', null],
            'nothing' => ['', null],
        ];
    }

    #[DataProvider('names')]
    public function test_names_match_one_zone_or_none(string $name, ?string $code)
    {
        $this->assertSame($code, $this->matcher()->zone($name));
    }

    /**
     * @return array<string, array{string, array{string, string}|null}>
     */
    public static function headings(): array
    {
        return [
            'an arrow' => ['Peninsular → Sabah & Labuan', ['peninsular-malaysia', 'sabah-labuan']],
            'a typed arrow' => ['Sarawak -> Peninsular Malaysia', ['sarawak', 'peninsular-malaysia']],
            'a dash' => ['West - Sarawak', ['peninsular-malaysia', 'sarawak']],
            'a dash without spaces' => ['Sabah-Sarawak', ['sabah-labuan', 'sarawak']],
            'to' => ['Sabah to Sarawak', ['sabah-labuan', 'sarawak']],
            'from and to' => ['From Sarawak to Sabah', ['sarawak', 'sabah-labuan']],
            'within' => ['Within Sarawak', ['sarawak', 'sarawak']],
            'a zone to itself' => ['Sarawak → Sarawak', ['sarawak', 'sarawak']],
            'escaped by an export' => ["'=Sarawak → Sabah", ['sarawak', 'sabah-labuan']],
            'one zone' => ['Sarawak', null],
            'not a route' => ['Weight (kg)', null],
        ];
    }

    /**
     * @param  array{string, string}|null  $route
     */
    #[DataProvider('headings')]
    public function test_route_headings_name_two_zones(string $heading, ?array $route)
    {
        $this->assertSame($route, $this->matcher()->route($heading));
    }

    public function test_east_matches_a_zone_with_all_of_east_malaysia()
    {
        $east = ['Sabah', 'Sarawak', 'Labuan'];
        $matcher = new ZoneMatcher([
            ['code' => 'semenanjung', 'name' => 'Semenanjung', 'states' => array_values(array_diff(array_column(MalaysianState::cases(), 'value'), $east))],
            ['code' => 'borneo', 'name' => 'Borneo', 'states' => $east],
        ]);

        $this->assertSame('borneo', $matcher->zone('East Malaysia'));
        $this->assertSame('semenanjung', $matcher->zone('West Malaysia'));
        $this->assertSame(['semenanjung', 'borneo'], $matcher->route('West - East'));
        $this->assertSame('Within Borneo', $matcher->routeName('borneo', 'borneo'));
        $this->assertSame('Semenanjung → Borneo', $matcher->routeName('semenanjung', 'borneo'));

        // A word in two zones' names fits neither.
        $halves = new ZoneMatcher([
            ['code' => 'west-malaysia', 'name' => 'West Malaysia', 'states' => ['Selangor']],
            ['code' => 'east-malaysia', 'name' => 'East Malaysia', 'states' => ['Sabah']],
        ]);
        $this->assertNull($halves->zone('Malaysia'));
    }

    private function matcher(): ZoneMatcher
    {
        $east = ['Sabah', 'Sarawak', 'Labuan'];

        return new ZoneMatcher([
            ['code' => 'peninsular-malaysia', 'name' => 'Peninsular Malaysia', 'states' => array_values(array_diff(array_column(MalaysianState::cases(), 'value'), $east))],
            ['code' => 'sabah-labuan', 'name' => 'Sabah & Labuan', 'states' => ['Sabah', 'Labuan']],
            ['code' => 'sarawak', 'name' => 'Sarawak', 'states' => ['Sarawak']],
        ]);
    }
}
