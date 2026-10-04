<?php

namespace App\Actions\RateCards;

use App\Enums\MalaysianState;
use App\Models\RateCard;
use App\Models\RateCardRoute;
use App\Models\RateCardZone;
use App\Support\RateSheets\Cells;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

class ExportRateCard
{
    /**
     * The label of the row that holds each route's price per extra kg. The
     * import reads it back (App\Support\RateSheets\Cells::isExtraKg()).
     */
    public const EXTRA_KG_LABEL = 'Each additional kg';

    /**
     * What the Matrix sheet puts where a route has no band at a weight. The
     * import reads it back (Cells::isNoBand()); an empty box would be a
     * missing price.
     */
    public const NO_BAND = 'n/a';

    /**
     * Write a version of the rates to a spreadsheet file and return its
     * path, for the admin to download (the caller deletes it after).
     *
     * An .xlsx file has three sheets: Rates (a row per band, then each
     * route's price per extra kg), Matrix (weights by route) and Zones (the
     * states in each). A .csv file holds the Rates sheet. Weights are in kg
     * and prices in ringgit, as people type them, and the file imports back
     * as the same card. Text is escaped so a spreadsheet program never runs
     * a zone name as a formula.
     *
     * @param  'xlsx'|'csv'  $format
     */
    public function handle(RateCard $card, string $format): string
    {
        $card->loadMissing(['zones', 'routes.bands']);
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'kotak-rates-'.Str::random(20).'.'.$format;

        if ($format === 'csv') {
            $writer = new CsvWriter;
            $writer->openToFile($path);
            $writer->addRows($this->ratesRows($card, csv: true));
            $writer->close();

            return $path;
        }

        $writer = new XlsxWriter;
        $writer->openToFile($path);

        $rates = $writer->getCurrentSheet();
        $rates->setName('Rates');
        $rates->setColumnWidth(26, 1, 2);
        $rates->setColumnWidth(18, 3);
        $rates->setColumnWidth(14, 4);
        $writer->addRows($this->ratesRows($card, csv: false));

        $matrix = $writer->addNewSheetAndMakeItCurrent();
        $matrix->setName('Matrix');
        $matrix->setColumnWidth(20, 1);
        $matrix->setColumnWidthForRange(30, 2, max(2, count($this->routes($card)) + 1));
        $writer->addRows($this->matrixRows($card));

        $zones = $writer->addNewSheetAndMakeItCurrent();
        $zones->setName('Zones');
        $zones->setColumnWidth(26, 1, 2);
        $zones->setColumnWidth(90, 3);
        $writer->addRows($this->zoneRows($card));

        $writer->close();

        return $path;
    }

    /**
     * Get the name the file downloads as, e.g. "kotak-rates-zone-rates.xlsx".
     *
     * @param  'xlsx'|'csv'  $format
     */
    public function fileName(RateCard $card, string $format): string
    {
        $slug = Str::slug($card->name) ?: "version-{$card->id}";

        return "kotak-rates-{$slug}.{$format}";
    }

    /**
     * A row per band, then the route's price per extra kg.
     *
     * @return list<Row>
     */
    private function ratesRows(RateCard $card, bool $csv): array
    {
        $rows = [$this->headings(['Origin', 'Destination', 'Max weight (kg)', 'Price (RM)'])];

        foreach ($this->routes($card) as [$from, $to, $route]) {
            foreach ($route->bands as $band) {
                $rows[] = new Row([
                    $this->text($from->name),
                    $this->text($to->name),
                    new NumericCell($band->max_weight_g / 1000),
                    $this->price($band->price_sen, $csv),
                ]);
            }

            if ($route->extra_kg_sen !== null) {
                $rows[] = new Row([
                    $this->text($from->name),
                    $this->text($to->name),
                    $this->text(self::EXTRA_KG_LABEL),
                    $this->price($route->extra_kg_sen, $csv),
                ]);
            }
        }

        return $rows;
    }

    /**
     * Weights down the first column, a column per route, and the prices
     * per extra kg in the last row. A route without a band at a weight has
     * "n/a" there.
     *
     * @return list<Row>
     */
    private function matrixRows(RateCard $card): array
    {
        $routes = $this->routes($card);
        $weights = collect($routes)
            ->flatMap(fn (array $route) => $route[2]->bands->pluck('max_weight_g'))
            ->unique()
            ->sort()
            ->values();

        $rows = [$this->headings([
            'Weight (kg)',
            ...array_map(fn (array $route): string => "{$route[0]->name} → {$route[1]->name}", $routes),
        ])];

        foreach ($weights as $grams) {
            $rows[] = new Row([
                new NumericCell($grams / 1000),
                ...array_map(function (array $route) use ($grams): Cell {
                    $band = $route[2]->bands->firstWhere('max_weight_g', $grams);

                    return $band === null ? $this->text(self::NO_BAND) : $this->price($band->price_sen, false);
                }, $routes),
            ]);
        }

        $rows[] = new Row([
            $this->text(self::EXTRA_KG_LABEL),
            ...array_map(fn (array $route): Cell => $route[2]->extra_kg_sen === null ? new EmptyCell(null) : $this->price($route[2]->extra_kg_sen, false), $routes),
        ]);

        return $rows;
    }

    /**
     * A row per zone: its name, code and states.
     *
     * @return list<Row>
     */
    private function zoneRows(RateCard $card): array
    {
        $rows = [$this->headings(['Zone', 'Code', 'States'])];

        foreach ($card->zones as $zone) {
            $rows[] = new Row([
                $this->text($zone->name),
                $this->text($zone->code),
                $this->text(implode(', ', array_map(
                    fn (string $state): string => MalaysianState::tryFrom($state)?->label() ?? $state,
                    $zone->states,
                ))),
            ]);
        }

        return $rows;
    }

    /**
     * Get the card's routes in zone order (from the first zone to each zone,
     * then the second…), each with its two zones. A draft may lack some.
     *
     * @return list<array{RateCardZone, RateCardZone, RateCardRoute}>
     */
    private function routes(RateCard $card): array
    {
        $routes = $card->routes->keyBy(fn (RateCardRoute $route): string => "{$route->origin_zone_id}-{$route->destination_zone_id}");
        $ordered = [];

        foreach ($card->zones as $from) {
            foreach ($card->zones as $to) {
                $route = $routes->get("{$from->id}-{$to->id}");

                if ($route !== null) {
                    $ordered[] = [$from, $to, $route];
                }
            }
        }

        return $ordered;
    }

    /**
     * A bold headings row.
     *
     * @param  list<string>  $headings
     */
    private function headings(array $headings): Row
    {
        $bold = new Style(fontBold: true);

        return new Row(array_map(fn (string $heading): Cell => new StringCell(Cells::escape($heading), $bold), $headings));
    }

    /**
     * A text cell, escaped so it is never run as a formula.
     */
    private function text(string $text): StringCell
    {
        return new StringCell(Cells::escape($text));
    }

    /**
     * A price in ringgit: a number shown with two decimals in a workbook,
     * written as "8.50" in a CSV file.
     */
    private function price(int $sen, bool $csv): Cell
    {
        return $csv
            ? new StringCell(number_format($sen / 100, 2, '.', ''))
            : new NumericCell($sen / 100, new Style(format: '0.00'));
    }
}
