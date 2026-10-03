<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Http\Resources\OrderSummaryResource;
use App\Http\Resources\UserResource;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    /**
     * List every order, newest first, filtered by status, branch and a search
     * on the tracking number or sender / receiver name.
     */
    public function index(Request $request): Response
    {
        // Each filter is a single plain value. Unknown values are ignored rather than rejected.
        $request->validate([
            'status' => ['nullable', 'string'],
            'branch' => ['nullable', 'string'],
            'q' => ['nullable', 'string'],
        ]);

        $status = OrderStatus::tryFrom($request->string('status')->value());
        $branchId = $request->integer('branch') ?: null;
        $search = $request->string('q')->trim()->substr(0, 100)->value();

        $orders = Order::query()
            ->with(['branch', 'customer', 'driver'])
            ->when($status, fn (Builder $orders, OrderStatus $status) => $orders->where('status', $status))
            ->when($branchId, fn (Builder $orders, int $branchId) => $orders->where('branch_id', $branchId))
            ->search($search)
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('admin/orders/Index', [
            'orders' => OrderSummaryResource::collection($orders),
            'filters' => [
                'status' => $status?->value,
                'q' => $search !== '' ? $search : null,
                'branch' => $branchId,
            ],
            'statuses' => OrderStatus::options(),
            'branches' => Branch::query()
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'city'])
                ->map(fn (Branch $branch): array => $branch->only(['id', 'code', 'name', 'city'])),
        ]);
    }

    /**
     * Show an order with its payment, delivery attempts and full history.
     *
     * While the order can be (re)assigned, the page also gets the active
     * drivers with their workload on the chosen day, for the assign panel.
     */
    public function show(Request $request, Order $order, Settings $settings): Response
    {
        Gate::authorize('view', $order);

        $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);

        $timezone = config()->string('kotak.timezone');
        $today = today($timezone);
        $date = $request->date('date', 'Y-m-d', $timezone) ?? $today;

        $order->load([
            'branch',
            'customer',
            'driver',
            'payment.receivedBy',
            'deliveryAttempts.driver',
            'statusEvents.branch',
            'statusEvents.actor',
        ])->loadCount('failedAttempts');

        return Inertia::render('admin/orders/Show', [
            'order' => OrderResource::make($order),
            'maxFailedAttempts' => $settings->maxFailedAttempts(),
            'date' => $date->toDateString(),
            'today' => $today->toDateString(),
            'drivers' => fn () => $order->canBeAssigned()
                ? UserResource::collection(User::query()
                    ->withRole(Role::Driver)
                    ->active()
                    ->withJobsCountOn($date)
                    ->orderBy('name')
                    ->get())
                : [],
        ]);
    }
}
