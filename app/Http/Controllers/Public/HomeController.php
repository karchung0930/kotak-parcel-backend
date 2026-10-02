<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\BranchResource;
use App\Models\Branch;
use App\Support\PriceCalculator;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    /**
     * Show the home page with the branch finder and the price estimator.
     *
     * The nearest branch is worked out in the browser, so the visitor's
     * location never reaches the server.
     */
    public function __invoke(PriceCalculator $pricing): Response
    {
        return Inertia::render('Welcome', [
            'branches' => BranchResource::collection(Branch::query()->active()->orderBy('name')->get()),
            'pricing' => $pricing->toArray(),
        ]);
    }
}
