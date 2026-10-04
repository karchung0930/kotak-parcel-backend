<?php

namespace App\Http\Controllers\Admin;

use App\Actions\RateCards\ExportRateCard;
use App\Http\Controllers\Controller;
use App\Models\RateCard;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class RateCardExportController extends Controller
{
    /**
     * Download a version of the rates as an Excel workbook (Rates, Matrix
     * and Zones sheets) or a CSV file. The current rates' workbook is the
     * template the Rates page offers for imports.
     *
     * @param  'xlsx'|'csv'  $format  limited by the route
     */
    public function __invoke(RateCard $rateCard, string $format, ExportRateCard $export): BinaryFileResponse
    {
        Gate::authorize('view', $rateCard);

        $format = $format === 'csv' ? 'csv' : 'xlsx';

        return response()
            ->download($export->handle($rateCard, $format), $export->fileName($rateCard, $format), [
                'Content-Type' => $format === 'csv' ? 'text/csv; charset=UTF-8' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Cache-Control' => 'private, no-store',
            ])
            ->deleteFileAfterSend();
    }
}
