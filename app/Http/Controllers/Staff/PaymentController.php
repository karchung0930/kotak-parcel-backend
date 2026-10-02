<?php

namespace App\Http\Controllers\Staff;

use App\Actions\Payments\RecordPayment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\StorePaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class PaymentController extends Controller
{
    /**
     * Take payment for a weighed parcel and show its receipt.
     */
    public function store(StorePaymentRequest $request, Order $order, RecordPayment $recordPayment): RedirectResponse
    {
        $payment = $recordPayment->handle(
            $order,
            $request->user(),
            $request->paymentMethod(),
            $request->integer('amount_sen'),
            // Only present for card payments (see StorePaymentRequest).
            $request->validated('reference'),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Payment received. The parcel is ready for dispatch.']);

        return to_route('staff.payments.receipt', $payment);
    }

    /**
     * Show a printable receipt for a payment.
     */
    public function show(Payment $payment): Response
    {
        Gate::authorize('view', $payment);

        $payment->loadMissing(['order', 'branch', 'receivedBy']);

        return Inertia::render('staff/Receipt', [
            'payment' => PaymentResource::make($payment),
        ]);
    }
}
