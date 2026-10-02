<?php

namespace App\Http\Controllers\Staff;

use App\Actions\Orders\CancelOrder;
use App\Actions\Orders\RecordDropOff;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\CancelOrderRequest;
use App\Http\Requests\Staff\DropOffRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Support\PriceCalculator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    /**
     * Show a parcel at the counter, ready to be weighed, paid for or cancelled.
     */
    public function show(Order $order, PriceCalculator $pricing): Response
    {
        Gate::authorize('view', $order);

        $order->load([
            'branch',
            'customer',
            'driver',
            'payment.receivedBy',
            'latestAttempt',
            'statusEvents.branch',
            'statusEvents.actor',
        ]);

        return Inertia::render('staff/OrderShow', [
            'order' => OrderResource::make($order),
            'pricing' => $pricing->toArray(),
            'paymentMethods' => PaymentMethod::options(),
        ]);
    }

    /**
     * Record the parcel's measured weight (and any corrected dimensions) and set its final price.
     */
    public function dropOff(DropOffRequest $request, Order $order, RecordDropOff $recordDropOff): RedirectResponse
    {
        $order = $recordDropOff->handle(
            $order,
            $request->user(),
            $request->integer('measured_weight_g'),
            $request->dimension('length_cm'),
            $request->dimension('width_cm'),
            $request->dimension('height_cm'),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => sprintf('Parcel received. The final price is RM %s.', number_format((int) $order->final_price_sen / 100, 2)),
        ]);

        return to_route('staff.orders.show', $order);
    }

    /**
     * Cancel a weighed parcel because the customer declined the final price.
     */
    public function cancel(CancelOrderRequest $request, Order $order, CancelOrder $cancelOrder): RedirectResponse
    {
        $order = $cancelOrder->handle($order, $request->user(), $request->string('reason')->value() ?: null);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Order cancelled. Please hand the parcel back to the customer.']);

        return to_route('staff.orders.show', $order);
    }
}
