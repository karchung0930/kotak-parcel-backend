<?php

namespace App\Http\Controllers\Public;

use App\Actions\Orders\StopReceiverEmails;
use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The page behind the link in each receiver email, where the receiver stops
 * the emails about their parcel. The receiver has no account: the signed
 * link is the permission, and the page shows nothing but the tracking
 * number, which the email already gave.
 */
class ReceiverEmailController extends Controller
{
    /**
     * Show whether the emails about the parcel are still on, with the button that stops them.
     */
    public function show(Order $order): Response
    {
        return Inertia::render('deliveries/Emails', [
            'trackingNumber' => $order->formatted_tracking_number,
            'stopped' => $order->receiver_email === null,
            'stopUrl' => $order->stopReceiverEmailsUrl(),
        ]);
    }

    /**
     * Stop the emails, from the page's button or from a mail app's
     * unsubscribe button, which posts "List-Unsubscribe=One-Click" (RFC 8058)
     * and only needs to hear that it worked.
     */
    public function destroy(Request $request, Order $order, StopReceiverEmails $stopReceiverEmails): RedirectResponse|HttpResponse
    {
        $stopReceiverEmails->handle($order);

        if ($request->input('List-Unsubscribe') === 'One-Click') {
            return response()->noContent();
        }

        return redirect()->to($order->stopReceiverEmailsUrl());
    }
}
