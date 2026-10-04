<?php

namespace Tests\Unit\Support\RateSheets;

use App\Support\RateSheets\Cells;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CellsTest extends TestCase
{
    /**
     * @return array<string, array{string|int|float|null, array{float, string|null}|null}>
     */
    public static function numbers(): array
    {
        return [
            'a whole number' => [8, [8.0, null]],
            'a decimal' => [8.5, [8.5, null]],
            'text of a number' => [' 12.50 ', [12.5, null]],
            'ringgit in front' => ['RM 8.50', [8.5, 'rm']],
            'ringgit stuck on' => ['rm8', [8.0, 'rm']],
            'MYR after' => ['1,200.00 MYR', [1200.0, 'rm']],
            'thousands' => ['1,200', [1200.0, null]],
            'thousands with decimals' => ['1,200.50', [1200.5, null]],
            'sen' => ['850 sen', [850.0, 'sen']],
            'kg' => ['2kg', [2.0, 'kg']],
            'kilograms' => ['0.5 kilograms', [0.5, 'kg']],
            'grams' => ['500 g', [500.0, 'g']],
            'up to' => ['Up to 2 kg', [2.0, 'kg']],
            'at most' => ['≤ 1kg', [1.0, 'kg']],
            'first' => ['First 1kg', [1.0, 'kg']],
            'and below' => ['1kg & below', [1.0, 'kg']],
            'and below in words' => ['2 kg and below', [2.0, 'kg']],
            'or less' => ['500g or less', [500.0, 'g']],
            'a range' => ['1.01 - 2 kg', [2.0, 'kg']],
            'a range with to' => ['501 to 1000 g', [1000.0, 'g']],
            'a negative number' => ['-5', [-5.0, null]],
            // Without decimal commas, a comma only groups thousands.
            'a decimal comma' => ['8,50', null],
            'a short decimal comma' => ['0,5', null],
            'a comma in the wrong place' => ['12,5000', null],
            'two points' => ['1.2.3', null],
            'words' => ['abc', null],
            'a word and a number' => ['Zone 2', null],
            'over' => ['Over 30 kg', null],
            'empty' => ['', null],
            'nothing' => [null, null],
        ];
    }

    /**
     * @param  array{float, string|null}|null  $expected
     */
    #[DataProvider('numbers')]
    public function test_numbers_are_read_with_their_unit(string|int|float|null $value, ?array $expected)
    {
        $this->assertSame($expected, Cells::number($value));
    }

    /**
     * @return array<string, array{string, array{float, string|null}|null}>
     */
    public static function decimalCommas(): array
    {
        return [
            'a decimal comma' => ['8,50', [8.5, null]],
            'a short decimal comma' => ['0,5', [0.5, null]],
            'four decimals' => ['12,5000', [12.5, null]],
            'with ringgit' => ['RM 8,50', [8.5, 'rm']],
            'thousands with points' => ['1.200,50', [1200.5, null]],
            'a decimal point still' => ['9.00', [9.0, null]],
            'a range' => ['1,01 - 2 kg', [2.0, 'kg']],
            'two commas' => ['1,2,3', null],
        ];
    }

    /**
     * @param  array{float, string|null}|null  $expected
     */
    #[DataProvider('decimalCommas')]
    public function test_a_file_with_decimal_commas_reads_a_comma_as_the_decimal_point(string $value, ?array $expected)
    {
        $this->assertSame($expected, Cells::number($value, decimalComma: true));
    }

    public function test_the_price_per_extra_kg_is_recognised_in_many_wordings()
    {
        foreach (['Each additional kg', 'Additional kg', 'Additional 1kg', 'additional 1 kg', 'Per kg', 'Extra kg', 'Each kg over 5 kg', 'Tambahan 1kg', 'Next 1kg', 'Every kg thereafter', 'Per 500g', 'Additional', 'EXTRA'] as $label) {
            $this->assertTrue(Cells::isExtraKg($label), $label);
        }

        // Where prices start is not enough: such a row may be a band.
        foreach (['Up to 1 kg', '1 kg', 'Over 30', 'Over 30 kg', '5kg and above', 'Above 5 kg', 'Weight', '', 'Peninsular Malaysia'] as $label) {
            $this->assertFalse(Cells::isExtraKg($label), $label);
        }

        $this->assertFalse(Cells::isExtraKg(5));
        $this->assertFalse(Cells::isExtraKg(null));
    }

    public function test_the_step_a_price_per_extra_kg_is_for_is_read()
    {
        $steps = [
            'Each additional kg' => null,
            'Each kg over 5 kg' => null,
            'Every kg thereafter' => null,
            'Additional 1kg' => 1000,
            'Next 1 kg' => 1000,
            'Each additional 1000 g' => 1000,
            'Per 0.5 kg' => 500,
            'Next 0.5kg' => 500,
            'Per 0,5 kg' => 500,
            'Each additional 500 g' => 500,
            'Per 250g' => 250,
        ];

        foreach ($steps as $label => $grams) {
            $this->assertSame($grams, Cells::extraStepG($label), $label);
        }

        $this->assertNull(Cells::extraStepG(1));
    }

    public function test_weights_that_say_where_prices_start_are_told_apart()
    {
        foreach (['Over 30 kg', '5kg and above', '10 kg onwards', 'More than 5 kg'] as $label) {
            $this->assertTrue(Cells::isAboveWeight($label), $label);
        }

        foreach (['Up to 30 kg', 'Each additional kg', 30, null] as $label) {
            $this->assertFalse(Cells::isAboveWeight($label), (string) $label);
        }
    }

    public function test_a_box_says_no_band_with_n_a_or_a_dash()
    {
        foreach (['n/a', 'N/A', ' na ', '-', "'-", '—', '–', 'None'] as $value) {
            $this->assertTrue(Cells::isNoBand($value), $value);
        }

        foreach (['', 'abc', '0', 0, null] as $value) {
            $this->assertFalse(Cells::isNoBand($value), (string) $value);
        }
    }

    public function test_columns_are_lettered_as_in_a_spreadsheet()
    {
        $this->assertSame(['A', 'B', 'Z', 'AA', 'AZ', 'BA', 'IW'], array_map(Cells::column(...), [0, 1, 25, 26, 51, 52, 256]));
    }

    public function test_formula_characters_are_escaped_and_read_back()
    {
        foreach (['=1+2', '+60123', '-East', '@SUM(A1)', "\tTab", "\rReturn"] as $text) {
            $this->assertSame("'{$text}", Cells::escape($text));
            $this->assertSame($text, Cells::unescape(Cells::escape($text)));
        }

        $this->assertSame('Sabah & Labuan', Cells::escape('Sabah & Labuan'));
        $this->assertSame('', Cells::escape(''));
        // An apostrophe before ordinary text is the text's own.
        $this->assertSame("'Tis", Cells::unescape("'Tis"));
    }

    public function test_cells_read_as_text_and_words()
    {
        $this->assertSame(['8', '8.5', '0.1', '', 'Sabah'], array_map(Cells::text(...), [8, 8.5, 0.1, null, ' Sabah ']));
        $this->assertSame('max weight kg', Cells::words('Max. Weight (KG)'));
        $this->assertSame('destinasi', Cells::words('Destinasí'));
    }

    public function test_a_long_cell_is_cut_short_when_quoted()
    {
        $this->assertSame('Sarawak', Cells::quote(' Sarawak '));
        $this->assertSame(str_repeat('x', 40).'...', Cells::quote(str_repeat('x', 5000)));
    }
}
