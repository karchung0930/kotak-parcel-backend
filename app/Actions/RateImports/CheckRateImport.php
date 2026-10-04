<?php

namespace App\Actions\RateImports;

use App\Enums\RateImportStatus;
use App\Models\RateImport;
use App\Support\RateSheets\Problems;
use App\Support\RateSheets\RateSheetParser;
use App\Support\RateSheets\SheetReader;
use App\Support\RateSheets\ZoneMatcher;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Exception\OpenSpoutException;
use Throwable;

class CheckRateImport
{
    /**
     * Check every row of the file with the confirmed mapping (run by
     * App\Jobs\ValidateRateImport). Without problems the import is ready,
     * holding every route with its bands in grams and sen; otherwise it
     * fails with the problems found (RateSheetParser). Nothing is written to
     * the rate card tables.
     *
     * The result is only saved if the import is still waiting for this
     * check with the same mapping, so a check overtaken by a newer mapping
     * (or a second copy of the job) changes nothing.
     */
    public function handle(RateImport $import): void
    {
        $import = $import->fresh();

        if ($import === null || $import->status !== RateImportStatus::Validating || $import->mapping === null || $import->layout === null) {
            return;
        }

        $mapping = $import->mapping;
        $layout = $import->layout;

        try {
            $result = $this->check($import);
        } catch (OpenSpoutException) {
            $result = self::failure('The file could not be read. Open it in Excel, save it again as .xlsx or .csv and upload it.');
        } catch (Throwable $e) {
            report($e);
            $result = self::failure('The file could not be read. Open it in Excel, save it again as .xlsx or .csv and upload it.');
        }

        DB::transaction(function () use ($import, $mapping, $layout, $result): void {
            $import = $import->freshLocked();

            if ($import->status === RateImportStatus::Validating && $import->layout === $layout && $import->mapping == $mapping) {
                $import->forceFill($result)->save();
            }
        });
    }

    /**
     * Mark an import whose checking job gave up (it kept failing or timed out) as failed.
     */
    public function giveUp(RateImport $import): void
    {
        RateImport::query()
            ->whereKey($import->id)
            ->where('status', RateImportStatus::Validating)
            ->update([
                'status' => RateImportStatus::Failed,
                'errors' => json_encode(Problems::only('Checking the file took too long or kept failing. Check it again, or split it into smaller files.')),
                'updated_at' => now(),
            ]);
    }

    /**
     * Read every row and work out what to store on the import.
     *
     * @return array<string, mixed>
     */
    private function check(RateImport $import): array
    {
        $path = $import->localPath();

        if ($path === null) {
            return self::failure('The file is no longer kept (files are deleted after '.RateImport::KEEP_FILES_DAYS.' days). Upload it again.');
        }

        $zones = $import->baseZones();

        if ($zones === null || $import->mapping === null || $import->layout === null) {
            return self::failure('The rates chosen for the zones were deleted or have no zones. Upload the file again and choose other rates.');
        }

        $reader = new SheetReader($path, $import->format());
        $parser = new RateSheetParser(new ZoneMatcher($zones), array_column($zones, 'code'), config()->integer('kotak.max_weight_g'), $reader->decimalComma());
        $result = $parser->parse($reader->rows($import->sheet), $import->layout, $import->mapping);

        if ($result['problems']->count() > 0) {
            return [
                'status' => RateImportStatus::Failed,
                'errors' => $result['problems']->toArray(),
                'summary' => ['rows' => $result['rows'], 'bands' => 0, 'routes' => []],
            ];
        }

        return [
            'status' => RateImportStatus::Ready,
            'errors' => null,
            'summary' => [
                'rows' => $result['rows'],
                'bands' => array_sum(array_map(fn (array $route): int => count($route['bands']), $result['routes'])),
                'routes' => $result['routes'],
            ],
        ];
    }

    /**
     * What to store on an import whose file cannot be checked.
     *
     * @return array<string, mixed>
     */
    private static function failure(string $message): array
    {
        return [
            'status' => RateImportStatus::Failed,
            'summary' => null,
            'errors' => Problems::only($message),
        ];
    }
}
