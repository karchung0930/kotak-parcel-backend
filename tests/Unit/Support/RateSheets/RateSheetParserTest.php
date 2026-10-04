<?php

namespace Tests\Unit\Support\RateSheets;

use App\Enums\RateImportLayout;
use App\Support\RateSheets\RateSheetParser;
use App\Support\RateSheets\ZoneMatcher;
use Tests\TestCase;

class RateSheetParserTest extends TestCase
{
    public function test_problems_quote_a_long_cell_only_in_part()
    {
        $zones = [['code' => 'malaysia', 'name' => 'Malaysia', 'states' => []]];
        $long = str_repeat('Lorem ipsum ', 500);

        $result = (new RateSheetParser(new ZoneMatcher($zones), ['malaysia'], 30000))->parse([
            1 => ['Origin', 'Destination', 'Weight', 'Price'],
            2 => [$long, 'Malaysia', 1, 8],
            3 => ['Malaysia', 'Malaysia', $long, 8],
            4 => ['Malaysia', 'Malaysia', 1, $long],
        ], RateImportLayout::Long, [
            'header_row' => 1,
            'columns' => ['origin' => 0, 'destination' => 1, 'weight' => 2, 'price' => 3],
            'weight_unit' => 'kg',
            'price_unit' => 'rm',
        ]);

        $messages = array_column(array_slice($result['problems']->toArray()['items'], 0, 3), 'message');

        $this->assertSame([
            '“Lorem ipsum Lorem ipsum Lorem ipsum Lore...” is not one of the zones (Malaysia).',
            '“Lorem ipsum Lorem ipsum Lorem ipsum Lore...” is not a weight.',
            '“Lorem ipsum Lorem ipsum Lorem ipsum Lore...” is not a price.',
        ], $messages);

        foreach ($messages as $message) {
            $this->assertLessThan(100, mb_strlen($message));
        }
    }
}
