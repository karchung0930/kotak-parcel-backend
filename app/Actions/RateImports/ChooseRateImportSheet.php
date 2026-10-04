<?php

namespace App\Actions\RateImports;

use App\Enums\RateImportStatus;
use App\Jobs\ParseRateImport;
use App\Models\RateImport;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ChooseRateImportSheet
{
    /**
     * Read another sheet of the workbook: the mapping and any problems found
     * go, and the file is queued to be read again (App\Jobs\ParseRateImport).
     *
     * @throws ValidationException when the sheet cannot be changed any more, or the file is gone
     */
    public function handle(RateImport $import, string $sheet): RateImport
    {
        return DB::transaction(function () use ($import, $sheet): RateImport {
            $import = $import->freshLocked();

            // Under "import", as the page shows it whatever it shows next:
            // a page opened before the import moved on no longer has the form.
            if (! $import->canChooseSheet()) {
                throw ValidationException::withMessages(['import' => match ($import->status) {
                    RateImportStatus::Applied => 'A draft was already made from this file.',
                    RateImportStatus::Validating => 'Every row is being checked. Wait a moment and try again.',
                    RateImportStatus::Uploaded, RateImportStatus::Parsing => 'The file is still being read. Wait a moment and try again.',
                    default => 'The sheet cannot be changed now.',
                }]);
            }

            if ($import->localPath() === null) {
                throw ValidationException::withMessages(['import' => 'The file is no longer kept (files are deleted after '.RateImport::KEEP_FILES_DAYS.' days). Upload it again.']);
            }

            $import->forceFill([
                'sheet' => $sheet,
                'status' => RateImportStatus::Uploaded,
                'layout' => null,
                'mapping' => null,
                'errors' => null,
                'summary' => null,
            ])->save();

            ParseRateImport::dispatch($import);

            return $import;
        });
    }
}
