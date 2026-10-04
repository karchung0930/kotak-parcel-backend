<?php

namespace App\Actions\RateImports;

use App\Models\RateImport;
use App\Support\RateSheets\Problems;
use Illuminate\Support\Facades\Storage;

class PruneRateImportFiles
{
    /**
     * Delete the uploaded files of imports older than a week, and return how
     * many were deleted. The imports stay, with the prices checked (a
     * checked file can still become a draft), but they cannot be read again.
     * The file's own text goes with it: the preview's rows, and the
     * problems found, which quote cells. Files no import points at, left by
     * an upload that failed halfway, go too once they are a week old.
     */
    public function handle(): int
    {
        $before = now()->subDays(RateImport::KEEP_FILES_DAYS);
        $disk = Storage::disk('local');
        $deleted = 0;

        foreach (RateImport::query()->whereNotNull('path')->where('created_at', '<', $before)->lazyById() as $import) {
            $disk->delete((string) $import->path);
            $import->forceFill([
                'path' => null,
                'preview' => $import->preview === null ? null : array_replace($import->preview, ['rows' => []]),
                'errors' => $import->errors === null ? null : Problems::only('The file was deleted after '.RateImport::KEEP_FILES_DAYS.' days, with the problems found in it. Upload it again to check it.'),
            ])->save();
            $deleted++;
        }

        $kept = RateImport::query()->whereNotNull('path')->pluck('path')->flip();

        foreach ($disk->files('rate-imports') as $file) {
            if (! $kept->has($file) && $disk->lastModified($file) < $before->getTimestamp()) {
                $disk->delete($file);
                $deleted++;
            }
        }

        return $deleted;
    }
}
