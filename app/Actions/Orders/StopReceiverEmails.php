<?php

namespace App\Actions\Orders;

use App\Models\Order;

class StopReceiverEmails
{
    /**
     * Stop the delivery emails to the receiver, at their request (the link
     * in each email). The address is removed from the order, so nothing more
     * goes to it, not even an email already queued
     * (ReceiverStatusUpdated::shouldSend()). Asking again changes nothing.
     */
    public function handle(Order $order): void
    {
        if ($order->receiver_email !== null) {
            $order->forceFill(['receiver_email' => null])->save();
        }
    }
}
