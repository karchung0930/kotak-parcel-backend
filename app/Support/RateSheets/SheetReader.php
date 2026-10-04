<?php

namespace App\Support\RateSheets;

use DateInterval;
use DateTimeInterface;
use Generator;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Streams the rows of an .xlsx or .csv file with OpenSpout, so even a large
 * file is read in a few MB of memory. Rows come keyed by their row number in
 * the sheet (from 1), skipping empty ones, and cells come as text, whole or
 * decimal numbers, or null when empty. A formula cell gives the value Excel
 * saved for it.
 *
 * CSV files may use commas, semicolons or tabs between fields (a sample of
 * the file decides), in UTF-8 or the Windows encoding Excel often saves in.
 * A file separated by semicolons writes decimals with a comma ("8,50").
 */
final class SheetReader
{
    /**
     * The most columns read from a row: a weight column and a route for
     * every ordered pair of 16 zones.
     */
    public const MAX_COLUMNS = 257;

    /**
     * Reading stops after this many empty rows in a row. Prices never have
     * such a gap, and a small workbook can claim a million empty rows.
     */
    public const MAX_EMPTY_ROWS = 1000;

    /**
     * The CSV file's field separator and encoding, once worked out.
     *
     * @var array{FIELD_DELIMITER: string, ENCODING: string}|null
     */
    private ?array $csv = null;

    /**
     * Create a new reader for the file at the given path.
     *
     * @param  'xlsx'|'csv'  $format
     */
    public function __construct(
        private string $path,
        private string $format,
    ) {}

    /**
     * Get the names of the workbook's sheets, in order. A CSV file has one
     * sheet without a name, so the list is empty.
     *
     * @return list<string>
     */
    public function sheetNames(): array
    {
        if ($this->format === 'csv') {
            return [];
        }

        $reader = new XlsxReader(new XlsxOptions(SHOULD_PRESERVE_EMPTY_ROWS: true));
        $reader->open($this->path);

        try {
            $names = [];

            foreach ($reader->getSheetIterator() as $sheet) {
                $names[] = $sheet->getName();
            }

            return $names;
        } finally {
            $reader->close();
        }
    }

    /**
     * Determine if the file writes decimals with a comma: a CSV file
     * separated by semicolons. Numbers in a workbook are stored as numbers.
     */
    public function decimalComma(): bool
    {
        return $this->format === 'csv' && $this->csvSettings()['FIELD_DELIMITER'] === ';';
    }

    /**
     * Stream the non-empty rows of a sheet (the first one when no name is
     * given, or when the name is not in the workbook), keyed by row number,
     * until MAX_EMPTY_ROWS empty rows in a row.
     *
     * @return Generator<int, list<string|int|float|null>>
     */
    public function rows(?string $sheet = null): Generator
    {
        $reader = $this->format === 'csv'
            ? new CsvReader(new CsvOptions(true, ...$this->csvSettings()))
            : new XlsxReader(new XlsxOptions(SHOULD_PRESERVE_EMPTY_ROWS: true));

        $reader->open($this->path);

        try {
            $chosen = null;

            foreach ($reader->getSheetIterator() as $candidate) {
                $chosen ??= $candidate;

                if ($sheet !== null && $candidate->getName() === $sheet) {
                    $chosen = $candidate;

                    break;
                }
            }

            if ($chosen === null) {
                return;
            }

            $number = 0;
            $empty = 0;

            foreach ($chosen->getRowIterator() as $row) {
                $number++;
                $cells = self::cells($row);

                if ($cells !== []) {
                    $empty = 0;

                    yield $number => $cells;
                } elseif (++$empty >= self::MAX_EMPTY_ROWS) {
                    break;
                }
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * Get a row's cells, without the empty ones at its end (an empty list
     * for an empty row).
     *
     * @return list<string|int|float|null>
     */
    private static function cells(Row $row): array
    {
        $cells = [];

        foreach ($row->cells as $index => $cell) {
            if ($index >= self::MAX_COLUMNS) {
                break;
            }

            $value = $cell instanceof FormulaCell ? $cell->getComputedValue() : $cell->getValue();

            $cells[$index] = match (true) {
                is_int($value), is_float($value) => $value,
                is_string($value) => trim($value) === '' ? null : trim($value),
                is_bool($value) => $value ? 'TRUE' : 'FALSE',
                $value instanceof DateTimeInterface => $value->format('Y-m-d'),
                $value instanceof DateInterval => $value->format('%h:%I'),
                default => null,
            };
        }

        $filled = [];
        $last = -1;

        foreach ($cells as $index => $value) {
            if ($value !== null) {
                $last = max($last, $index);
            }
        }

        for ($index = 0; $index <= $last; $index++) {
            $filled[] = $cells[$index] ?? null;
        }

        return $filled;
    }

    /**
     * Work out the CSV file's field separator and encoding from its start.
     *
     * @return array{FIELD_DELIMITER: string, ENCODING: string}
     */
    private function csvSettings(): array
    {
        if ($this->csv !== null) {
            return $this->csv;
        }

        $sample = (string) file_get_contents($this->path, length: 8192);
        $line = strtok(ltrim($sample, "\xEF\xBB\xBF\r\n"), "\r\n") ?: '';

        // The separator used most on the first line, outside quotes.
        $unquoted = (string) preg_replace('/"[^"]*"/', '', $line);
        $counts = [',' => substr_count($unquoted, ','), ';' => substr_count($unquoted, ';'), "\t" => substr_count($unquoted, "\t")];
        arsort($counts);
        $delimiter = (string) array_key_first($counts);

        // The sample may end inside a character, so its last bytes are not judged.
        $whole = strlen($sample) < 8192 ? $sample : mb_strcut($sample, 0, 8188, 'UTF-8');

        return $this->csv = [
            'FIELD_DELIMITER' => $counts[$delimiter] > 0 ? $delimiter : ',',
            'ENCODING' => mb_check_encoding($whole, 'UTF-8') ? 'UTF-8' : 'Windows-1252',
        ];
    }
}
