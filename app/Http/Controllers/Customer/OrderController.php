<?php

namespace App\Http\Controllers\Customer;

use App\Actions\Orders\CancelOrder;
use App\Actions\Orders\CreateOrder;
use App\Enums\MalaysianState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\CancelOrderRequest;
use App\Http\Requests\Customer\StoreOrderRequest;
use App\Http\Resources\BranchResource;
use App\Http\Resources\OrderResource;
use App\Http\Resources\OrderSummaryResource;
use App\Models\Branch;
use App\Models\Order;
use App\Support\PriceCalculator;
use App\Support\RateCards;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    /**
     * List the customer's own orders, newest first.
     */
    public function index(Request $request): Response
    {
        $orders = Order::query()
            ->forCustomer($request->user())
            ->with('branch')
            ->latest()
            ->latest('id')
            ->paginate(10);

        return Inertia::render('orders/Index', [
            'orders' => OrderSummaryResource::collection($orders),
        ]);
    }

    /**
     * Show the form for sending a parcel, with the current rate card for
     * the live estimate from the chosen branch to the address.
     */
    public function create(Request $request, PriceCalculator $pricing, RateCards $rateCards): Response
    {
        Gate::authorize('create', Order::class);

        $customer = $request->user();

        return Inertia::render('orders/Create', [
            'branches' => BranchResource::collection(Branch::query()->active()->orderBy('name')->get()),
            'pricing' => $pricing->publicRules($rateCards->current()),
            'states' => MalaysianState::options(),
            'sender' => ['name' => $customer->name, 'phone' => $customer->phone],
        ]);
    }

    /**
     * Create the order and show it with its tracking number.
     */
    public function store(StoreOrderRequest $request, CreateOrder $createOrder): RedirectResponse
    {
        $order = $createOrder->handle($request->user(), $request->validated());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Order :number created. Drop your parcel off at the branch to send it.', [
                'number' => $order->formatted_tracking_number,
            ]),
        ]);

        return to_route('orders.show', $order);
    }

    /**
     * Show one of the customer's orders with its tracking history.
     */
    public function show(Request $request, Order $order): Response
    {
        Gate::authorize('view', $order);

        // No "customer", "driver" or "statusEvents.actor": staff details stay internal.
        $order->load(['branch', 'payment', 'latestAttempt', 'statusEvents.branch']);

        return Inertia::render('orders/Show', [
            'order' => OrderResource::make($order),
            'canCancel' => $request->user()->can('cancel', $order),
        ]);
    }

    /**
     * Cancel the order before the parcel is dropped off.
     */
    public function cancel(CancelOrderRequest $request, Order $order, CancelOrder $cancelOrder): RedirectResponse
    {
        $cancelOrder->handle($order, $request->user(), $request->validated('reason'));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Order :number has been cancelled.', ['number' => $order->formatted_tracking_number]),
        ]);

        return to_route('orders.show', $order);
    }
}
