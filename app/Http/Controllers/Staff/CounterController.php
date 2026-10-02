<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderSummaryResource;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CounterController extends Controller
{
    /**
     * Show the branch counter, or open the parcel whose tracking number was entered.
     *
     * The number is accepted in any format ("KT-7Q4M92XD", "kt7q4m92xd").
     */
    public function __invoke(Request $request): Response|RedirectResponse
    {
        $request->validate(['number' => ['nullable', 'string']]);

        $query = $request->string('number')->trim()->substr(0, 32)->value();
        $order = $query !== '' ? Order::byTrackingNumber($query)->first() : null;

        if ($order !== null) {
            return to_route('staff.orders.show', $order);
        }

        $branchId = $request->user()->branch_id;

        // Parcels most recently received at this branch (every branch for admins without one).
        $recent = Order::query()
            ->with('branch')
            ->whereNotNull('dropped_off_at')
            ->when($branchId, fn (Builder $orders, int $branchId) => $orders->where('branch_id', $branchId))
            ->latest('dropped_off_at')
            ->limit(10)
            ->get();

        return Inertia::render('staff/Counter', [
            'query' => $query !== '' ? $query : null,
            'recent' => OrderSummaryResource::collection($recent),
        ]);
    }
}
