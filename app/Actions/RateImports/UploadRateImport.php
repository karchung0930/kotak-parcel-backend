<?php

namespace App\Actions\RateImports;

use App\Jobs\ParseRateImport;
use App\Models\RateCard;
use App\Models\RateImport;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class UploadRateImport
{
    /**
     * Keep an uploaded spreadsheet of prices and queue it to be read
     * (App\Jobs\ParseRateImport). The file goes on the private disk under a
     * random name; the daily clean-up deletes it after a week.
     *
     * @param  RateCard  $base  the card whose zones and divisor the prices use
     */
    public function handle(User $admin, UploadedFile $file, RateCard $base): RateImport
    {
        $format = strtolower($file->getClientOriginalExtension()) === 'csv' ? 'csv' : 'xlsx';
        $path = $file->storeAs('rate-imports', Str::uuid().'.'.$format, 'local');

        if ($path === false) {
            throw new RuntimeException('Unable to store the uploaded rates file.');
        }

        try {
            $import = (new RateImport)->forceFill([
                'user_id' => $admin->id,
                'base_rate_card_id' => $base->id,
                'original_name' => Str::limit(basename($file->getClientOriginalName()), 250, ''),
                'path' => $path,
            ]);
            $import->save();
        } catch (Throwable $e) {
            // Nothing points at the file, so do not keep it.
            Storage::disk('local')->delete($path);

            throw $e;
        }

        ParseRateImport::dispatch($import);

        return $import;
    }
}
