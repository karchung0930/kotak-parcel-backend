<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\BranchResource;
use App\Models\Branch;
use Inertia\Inertia;
use Inertia\Response;

class BranchController extends Controller
{
    /**
     * List the branches that accept parcels, grouped by state and city.
     */
    public function index(): Response
    {
        $branches = Branch::query()
            ->active()
            ->orderBy('state')
            ->orderBy('city')
            ->orderBy('name')
            ->get();

        return Inertia::render('branches/Index', [
            'branches' => BranchResource::collection($branches),
        ]);
    }
}
