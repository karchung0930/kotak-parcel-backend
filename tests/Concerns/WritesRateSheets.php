<?php

namespace Tests\Concerns;

use App\Models\RateCard;
use App\Models\RateImport;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Options as CsvOptions;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Spreadsheets of prices for the import tests, written with OpenSpout the
 * way Excel saves them: text as text (so "=1+2" is not a formula), numbers
 * as numbers. Rows are lists of cells; an empty list is an empty row.
 */
trait WritesRateSheets
{
    /**
     * Write a workbook with the given sheets (name => rows) and return its path.
     *
     * @param  array<string, list<list<string|int|float|null>>>  $sheets
     */
    protected function xlsxFile(array $sheets): string
    {
        $path = $this->sheetPath('xlsx');
        $writer = new XlsxWriter;
        $writer->openToFile($path);
        $first = true;

        foreach ($sheets as $name => $rows) {
            $sheet = $first ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
            $sheet->setName($name);
            $first = false;

            foreach ($rows as $row) {
                $writer->addRow(new Row(array_map(fn (string|int|float|null $value): Cell => match (true) {
                    $value === null => new EmptyCell(null),
                    is_string($value) => new StringCell($value),
                    default => new NumericCell($value),
                }, $row)));
            }
        }

        $writer->close();

        return $path;
    }

    /**
     * Write a CSV file and return its path.
     *
     * @param  list<list<string|int|float|null>>  $rows
     */
    protected function csvFile(array $rows, string $delimiter = ','): string
    {
        $path = $this->sheetPath('csv');
        $writer = new CsvWriter(new CsvOptions(FIELD_DELIMITER: $delimiter));
        $writer->openToFile($path);

        foreach ($rows as $row) {
            $writer->addRow(new Row(array_map(fn (string|int|float|null $value): Cell => $value === null ? new EmptyCell(null) : new StringCell((string) $value), $row)));
        }

        $writer->close();

        return $path;
    }

    /**
     * Get a written file as an upload, as a browser would send it.
     */
    protected function upload(string $path, string $name): UploadedFile
    {
        return new UploadedFile($path, $name, null, null, true);
    }

    /**
     * Upload a file as the admin (with the queue running synchronously,
     * it is read straight away) and return the import.
     */
    protected function uploadAs(User $admin, string $path, string $name, ?RateCard $base = null): RateImport
    {
        $this->actingAs($admin)
            ->post(route('admin.rates.imports.store'), array_filter([
                'file' => $this->upload($path, $name),
                'base_rate_card_id' => $base?->id,
            ]))
            ->assertSessionHasNoErrors();

        return RateImport::query()->latest('id')->firstOrFail();
    }

    /**
     * A long layout of the zone rates (CreatesRateCards): a row per band
     * and an extra-kg row per route, weights in kg and prices in ringgit.
     *
     * @return list<list<string|int|float|null>>
     */
    protected static function longZoneRates(): array
    {
        $names = ['peninsular-malaysia' => 'Peninsular Malaysia', 'sabah-labuan' => 'Sabah & Labuan', 'sarawak' => 'Sarawak'];
        $rows = [['Origin', 'Destination', 'Max weight (kg)', 'Price (RM)']];

        foreach (self::routes() as $route) {
            foreach ($route['bands'] as $band) {
                $rows[] = [$names[$route['from']], $names[$route['to']], $band['maxWeightG'] / 1000, $band['priceSen'] / 100];
            }

            $rows[] = [$names[$route['from']], $names[$route['to']], 'Each additional kg', $route['extraKgSen'] / 100];
        }

        return $rows;
    }

    /**
     * A path for a new spreadsheet file, deleted when the test ends.
     */
    private function sheetPath(string $extension): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'kotak-test-'.Str::random(12).'.'.$extension;
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        return $path;
    }
}
