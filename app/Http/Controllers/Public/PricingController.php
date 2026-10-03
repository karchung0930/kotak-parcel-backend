<?php

namespace App\Http\Controllers\Public;

use App\Enums\MalaysianState;
use App\Http\Controllers\Controller;
use App\Http\Resources\BranchResource;
use App\Models\Branch;
use App\Support\PriceCalculator;
use App\Support\RateCards;
use Inertia\Inertia;
use Inertia\Response;

class PricingController extends Controller
{
    /**
     * Show the current rates: which states are in which zone, each route's
     * weight bands and the estimator. When new rates are scheduled, the
     * page says from when.
     */
    public function __invoke(PriceCalculator $pricing, RateCards $rateCards): Response
    {
        $upcoming = $rateCards->upcoming();

        return Inertia::render('pricing/Index', [
            'pricing' => $pricing->publicRules($rateCards->current()),
            'upcoming' => $upcoming === null ? null : [
                'effectiveFrom' => $upcoming->effectiveFrom?->toIso8601ZuluString(),
            ],
            'branches' => BranchResource::collection(Branch::query()->active()->orderBy('name')->get()),
            'states' => MalaysianState::options(),
        ]);
    }
}
