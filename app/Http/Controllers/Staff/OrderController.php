<?php

namespace App\Http\Controllers\Staff;

use App\Actions\Orders\CancelOrder;
use App\Actions\Orders\RecordDropOff;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\CancelOrderRequest;
use App\Http\Requests\Staff\DropOffRequest;
use App\Http\Resources\OrderResource;
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
     * Show a parcel at the counter, ready to be weighed, paid for or cancelled.
     *
     * A parcel still to be weighed is priced live with the card in effect,
     * from this counter's branch (as RecordDropOff will). A weighed parcel
     * shows the card that set its final price, from where it was handed in.
     */
    public function show(Request $request, Order $order, PriceCalculator $pricing, RateCards $rateCards): Response
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
            'estimatedRateCard',
            'finalRateCard',
        ]);

        $weighed = $order->final_rate_card_id !== null ? $rateCards->find($order->final_rate_card_id) : null;
        $origin = $order->status === OrderStatus::Created ? ($request->user()->branch ?? $order->branch) : $order->branch;

        return Inertia::render('staff/OrderShow', [
            'order' => OrderResource::make($order),
            'pricing' => $pricing->rules($weighed ?? $rateCards->current()),
            'origin' => $origin->state->value,
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
