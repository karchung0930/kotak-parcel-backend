<?php

namespace App\Actions\RateImports;

use App\Enums\RateImportLayout;
use App\Enums\RateImportStatus;
use App\Jobs\ValidateRateImport;
use App\Models\RateImport;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * @phpstan-import-type Mapping from RateImport
 */
class ConfirmRateImportMapping
{
    /**
     * Save the mapping the admin confirmed and queue every row to be checked
     * with it (App\Jobs\ValidateRateImport). A file already checked can be
     * mapped again, until a draft is made from it.
     *
     * @param  Mapping  $mapping  validated by UpdateRateImportMappingRequest
     *
     * @throws ValidationException when the file can no longer be mapped, or is gone
     */
    public function handle(RateImport $import, RateImportLayout $layout, array $mapping): RateImport
    {
        return DB::transaction(function () use ($import, $layout, $mapping): RateImport {
            $import = $import->freshLocked();

            // Under "import", as the page shows it whatever it shows next:
            // a page opened before the import moved on no longer has the form.
            if (! $import->canBeMapped()) {
                throw ValidationException::withMessages(['import' => match ($import->status) {
                    RateImportStatus::Applied => 'A draft was already made from this file.',
                    RateImportStatus::Failed => 'This file could not be read. Upload it again.',
                    RateImportStatus::Validating => 'Every row is being checked. Wait a moment and try again.',
                    RateImportStatus::Uploaded, RateImportStatus::Parsing => 'The file is still being read. Wait a moment and try again.',
                    default => 'The columns cannot be changed now. Upload the file again.',
                }]);
            }

            if ($import->localPath() === null) {
                throw ValidationException::withMessages(['import' => 'The file is no longer kept (files are deleted after '.RateImport::KEEP_FILES_DAYS.' days). Upload it again.']);
            }

            $import->forceFill([
                'layout' => $layout,
                'mapping' => $mapping,
                'status' => RateImportStatus::Validating,
                'errors' => null,
                'summary' => null,
            ])->save();

            ValidateRateImport::dispatch($import);

            return $import;
        });
    }
}
