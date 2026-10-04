<?php

namespace App\Actions\RateImports;

use App\Enums\RateImportStatus;
use App\Models\RateImport;
use App\Support\RateSheets\Cells;
use App\Support\RateSheets\LayoutDetector;
use App\Support\RateSheets\Problems;
use App\Support\RateSheets\RateSheetParser;
use App\Support\RateSheets\SheetReader;
use App\Support\RateSheets\ZoneMatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OpenSpout\Common\Exception\OpenSpoutException;
use Throwable;

class ReadRateImport
{
    /**
     * How many rows the preview shows at least, and at most.
     */
    public const PREVIEW_ROWS = 15;

    public const MAX_PREVIEW_ROWS = 30;

    /**
     * The most rows read from a sheet: the preview's, then one more than
     * the check reads under the headings (RateSheetParser::MAX_ROWS). The
     * rest of a longer sheet is never needed.
     */
    public const MAX_SCAN_ROWS = self::MAX_PREVIEW_ROWS + RateSheetParser::MAX_ROWS + 1;

    /**
     * The most sheets a workbook may have, and the longest sheet name (as
     * stored on the import).
     */
    public const MAX_SHEETS = 50;

    public const MAX_SHEET_NAME = 100;

    /**
     * How many sheets are tried, in order, for the first with anything in it.
     */
    private const FIRST_SHEETS = 10;

    private const UNREADABLE = 'The file could not be read. Open it in Excel, save it again as .xlsx or .csv and upload it.';

    /**
     * Read an uploaded file (run by App\Jobs\ParseRateImport): its sheet
     * names, the chosen sheet's first rows and size, and the suggested
     * layout and mapping (LayoutDetector). The import then waits for the
     * admin to check the mapping, or fails when the file cannot be read.
     *
     * The import is marked as being read first, so its page says so. An
     * import that is no longer waiting to be read (a second copy of the job)
     * is left alone, and so is one changed while it was read.
     */
    public function handle(RateImport $import): void
    {
        $import = DB::transaction(function () use ($import): ?RateImport {
            $import = $import->freshLocked();

            if (! in_array($import->status, [RateImportStatus::Uploaded, RateImportStatus::Parsing], true)) {
                return null;
            }

            $import->forceFill(['status' => RateImportStatus::Parsing])->save();

            return $import;
        });

        if ($import === null) {
            return;
        }

        // Known when another sheet was chosen, and once read below: they
        // stay when reading fails, so a good sheet can still be chosen.
        $sheets = $import->preview['sheets'] ?? [];

        try {
            $result = $this->read($import, $sheets);
        } catch (OpenSpoutException) {
            $result = self::failure(self::UNREADABLE, $sheets);
        } catch (Throwable $e) {
            report($e);
            $result = self::failure(self::UNREADABLE, $sheets);
        }

        DB::transaction(function () use ($import, $result): void {
            $import = $import->freshLocked();

            if ($import->status === RateImportStatus::Parsing) {
                $import->forceFill($result)->save();
            }
        });
    }

    /**
     * Mark an import whose reading job gave up (it kept failing or timed out) as failed.
     */
    public function giveUp(RateImport $import): void
    {
        RateImport::query()
            ->whereKey($import->id)
            ->whereIn('status', [RateImportStatus::Uploaded, RateImportStatus::Parsing])
            ->update([
                'status' => RateImportStatus::Failed,
                'errors' => json_encode(Problems::only('Reading the file took too long or kept failing. Upload it again, or split it into smaller files.')),
                'updated_at' => now(),
            ]);
    }

    /**
     * Read the file and work out what to store on the import. The sheet
     * names are given back as soon as they are known.
     *
     * @param  list<string>  $sheets
     * @return array<string, mixed>
     */
    private function read(RateImport $import, array &$sheets): array
    {
        $path = $import->localPath();

        if ($path === null) {
            return self::failure('The file is no longer kept (files are deleted after '.RateImport::KEEP_FILES_DAYS.' days). Upload it again.');
        }

        $zones = $import->baseZones();

        if ($zones === null) {
            return self::failure('The rates chosen for the zones were deleted or have no zones. Upload the file again and choose other rates.');
        }

        $reader = new SheetReader($path, $import->format());
        $names = $reader->sheetNames();

        if (count($names) > self::MAX_SHEETS) {
            return self::failure('The workbook has more than '.self::MAX_SHEETS.' sheets. Keep the sheet with the prices and upload it again.');
        }

        foreach ($names as $name) {
            if (mb_strlen($name) > self::MAX_SHEET_NAME) {
                return self::failure('A sheet name is longer than '.self::MAX_SHEET_NAME.' characters. Shorten it and upload the file again.');
            }
        }

        $sheets = $names;

        // The sheet the admin chose, else the first one with anything in it.
        $candidates = in_array($import->sheet, $sheets, true)
            ? [$import->sheet]
            : ($sheets === [] ? [null] : array_slice($sheets, 0, self::FIRST_SHEETS));
        [$sheet, $first, $extraRows, $lastRow, $columns, $cutShort] = [$candidates[0], [], [], 0, 0, false];

        foreach ($candidates as $candidate) {
            [$first, $extraRows, $lastRow, $columns, $cutShort] = $this->scan($reader, $candidate);

            if ($first !== []) {
                $sheet = $candidate;

                break;
            }
        }

        if ($first === []) {
            return ['sheet' => $sheet] + self::failure(
                $sheets === [] ? 'The file is empty.' : 'The sheet “'.$sheet.'” is empty. Choose another sheet, or upload another file.',
                $sheets,
            );
        }

        [$layout, $mapping] = (new LayoutDetector(new ZoneMatcher($zones), config()->integer('kotak.max_weight_g'), $reader->decimalComma()))
            ->detect($first, $extraRows);

        // Enough rows to show the headings and the first rows under them.
        $headingAt = (int) array_search($mapping['header_row'], array_keys($first), true);
        $shown = array_slice($first, 0, min(self::MAX_PREVIEW_ROWS, max(self::PREVIEW_ROWS, $headingAt + 9)), true);

        return [
            'status' => RateImportStatus::NeedsMapping,
            'sheet' => $sheet,
            'layout' => $layout,
            'mapping' => $mapping,
            'errors' => null,
            'summary' => null,
            'preview' => [
                'sheets' => $sheets,
                'rows' => array_map(
                    fn (int $number, array $cells): array => [
                        'number' => $number,
                        'cells' => array_map(fn (string|int|float|null $value): string => Str::limit(Cells::text($value), 80), $cells),
                    ],
                    array_keys($shown),
                    $shown,
                ),
                'columns' => $columns,
                'last_row' => $lastRow,
                'cut_short' => $cutShort,
            ],
        ];
    }

    /**
     * Stream a sheet once, up to MAX_SCAN_ROWS rows: its first rows (keyed
     * by row number), the rows that mark a price per extra kg in their
     * first columns, the last row read, the widest row, and whether the
     * sheet goes on beyond what was read.
     *
     * @return array{array<int, list<string|int|float|null>>, array<int, list<int>>, int, int, bool}
     */
    private function scan(SheetReader $reader, ?string $sheet): array
    {
        $first = [];
        $extraRows = [];
        $lastRow = 0;
        $columns = 0;
        $read = 0;

        foreach ($reader->rows($sheet) as $number => $cells) {
            if (++$read > self::MAX_SCAN_ROWS) {
                return [$first, $extraRows, $lastRow, $columns, true];
            }

            if (count($first) < self::MAX_PREVIEW_ROWS) {
                $first[$number] = $cells;
            }

            foreach (array_slice($cells, 0, 5, true) as $column => $value) {
                if (Cells::isExtraKg($value)) {
                    $extraRows[$number][] = $column;
                }
            }

            $lastRow = $number;
            $columns = max($columns, count($cells));
        }

        return [$first, $extraRows, $lastRow, $columns, false];
    }

    /**
     * What to store on an import that cannot go on. The sheet names stay
     * when they are known, so another sheet can be chosen.
     *
     * @param  list<string>  $sheets
     * @return array<string, mixed>
     */
    private static function failure(string $message, array $sheets = []): array
    {
        return [
            'status' => RateImportStatus::Failed,
            'layout' => null,
            'mapping' => null,
            'preview' => $sheets === [] ? null : ['sheets' => $sheets, 'rows' => [], 'columns' => 0, 'last_row' => 0, 'cut_short' => false],
            'summary' => null,
            'errors' => Problems::only($message),
        ];
    }
}
