<?php

namespace App\Http\Controllers\Admin;

use App\Actions\RateImports\ChooseRateImportSheet;
use App\Actions\RateImports\ConfirmRateImportMapping;
use App\Actions\RateImports\CreateDraftFromRateImport;
use App\Actions\RateImports\UploadRateImport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ChooseRateImportSheetRequest;
use App\Http\Requests\Admin\StoreRateImportRequest;
use App\Http\Requests\Admin\UpdateRateImportMappingRequest;
use App\Http\Resources\RateCardResource;
use App\Http\Resources\RateImportResource;
use App\Http\Resources\RateImportSummaryResource;
use App\Models\RateCard;
use App\Models\RateImport;
use App\Support\RateCards;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class RateImportController extends Controller
{
    /**
     * Show the upload form, with the versions whose zones the prices can
     * use and the latest imports, to pick one up again.
     */
    public function create(RateCards $rateCards): Response
    {
        Gate::authorize('create', RateImport::class);

        return Inertia::render('admin/rates/imports/Create', [
            'rateCards' => RateCardResource::collection(RateCard::query()->has('zones')->newestFirst()->get()),
            'currentRateCardId' => $rateCards->current()->id,
            'recent' => RateImportSummaryResource::collection(
                RateImport::query()->with(['user', 'baseRateCard', 'rateCard'])->latest('id')->limit(10)->get(),
            ),
            'maxKb' => StoreRateImportRequest::MAX_KB,
        ]);
    }

    /**
     * Keep the uploaded file and queue it to be read, then open the import.
     */
    public function store(StoreRateImportRequest $request, UploadRateImport $upload): RedirectResponse
    {
        $import = $upload->handle($request->user(), $request->spreadsheet(), $request->base());

        return to_route('admin.rates.imports.show', $import);
    }

    /**
     * Show an import where it stands: being read or checked (the page asks
     * again every few seconds), the mapping to confirm, the problems found,
     * or the prices ready to become a draft.
     */
    public function show(Request $request, RateImport $rateImport): Response
    {
        Gate::authorize('view', $rateImport);

        $rateImport->load(['user', 'baseRateCard.zones', 'rateCard']);

        return Inertia::render('admin/rates/imports/Show', [
            'rateImport' => RateImportResource::make($rateImport),
            'zones' => $rateImport->baseZones() ?? [],
            'currentRateCardId' => app(RateCards::class)->current()->id,
            'maxKb' => StoreRateImportRequest::MAX_KB,
            'can' => [
                'map' => $rateImport->canBeMapped() && $rateImport->path !== null,
                'chooseSheet' => $rateImport->canChooseSheet() && $rateImport->path !== null,
                'createDraft' => $rateImport->canMakeDraft()
                    && $rateImport->baseRateCard !== null
                    && $request->user()->can('create', RateCard::class),
            ],
        ]);
    }

    /**
     * Read another sheet of the workbook.
     */
    public function sheet(ChooseRateImportSheetRequest $request, RateImport $rateImport, ChooseRateImportSheet $choose): RedirectResponse
    {
        $choose->handle($rateImport, (string) $request->validated('sheet'));

        return to_route('admin.rates.imports.show', $rateImport);
    }

    /**
     * Save the confirmed mapping and queue every row to be checked.
     */
    public function mapping(UpdateRateImportMappingRequest $request, RateImport $rateImport, ConfirmRateImportMapping $confirm): RedirectResponse
    {
        $confirm->handle($rateImport, $request->layout(), $request->mapping());

        return to_route('admin.rates.imports.show', $rateImport);
    }

    /**
     * Make a draft rate card from a checked file and open it.
     */
    public function draft(Request $request, RateImport $rateImport, CreateDraftFromRateImport $createDraft): RedirectResponse
    {
        Gate::authorize('update', $rateImport);
        Gate::authorize('create', RateCard::class);

        $draft = $createDraft->handle($rateImport, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Draft created from the file. Check it, then publish it.']);

        return to_route('admin.rates.show', $draft);
    }
}
