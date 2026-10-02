<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Support\PriceCalculator;
use Inertia\Inertia;
use Inertia\Response;

class PricingController extends Controller
{
    /**
     * Show the pricing rules, from config/kotak.php.
     */
    public function __invoke(PriceCalculator $pricing): Response
    {
        return Inertia::render('pricing/Index', [
            'pricing' => $pricing->toArray(),
        ]);
    }
}
