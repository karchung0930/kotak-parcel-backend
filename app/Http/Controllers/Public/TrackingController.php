<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\TrackingResource;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class TrackingController extends Controller
{
    /**
     * Show the public tracking page, and the parcel's progress when a number is given.
     *
     * Numbers are accepted with or without the hyphen and in any case. Input
     * that cannot be a tracking number simply finds nothing. The result is
     * public, so TrackingResource carries no personal data.
     */
    public function __invoke(Request $request): Response
    {
        $number = $request->query('number');

        // Echoed back on the page, so keep it a short plain string.
        $query = is_string($number) ? Str::limit($number, 32, '') : null;

        $order = $query === null ? null : Order::query()->byTrackingNumber($query)->first();

        return Inertia::render('track/Show', [
            'query' => $query,
            'result' => $order ? TrackingResource::make($order) : null,
        ]);
    }
}
