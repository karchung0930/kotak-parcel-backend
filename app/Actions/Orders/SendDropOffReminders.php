<?php

namespace App\Actions\Orders;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Notifications\DropOffReminder;
use Illuminate\Support\Facades\DB;

class SendDropOffReminders
{
    /**
     * Remind customers whose orders are still waiting for drop-off and will
     * soon be cancelled, and return how many reminders were queued.
     *
     * Each order is locked and marked as reminded in one transaction, so a
     * second or overlapping run never reminds anyone twice. Only active
     * customers with a verified email address are written to (the email's
     * link needs a working sign-in); the others are marked all the same.
     */
    public function handle(): int
    {
        $queued = 0;

        foreach (Order::query()->dueForDropOffReminder()->lazyById() as $order) {
            $order = DB::transaction(function () use ($order): ?Order {
                $order = $order->freshLocked();

                if ($order->status !== OrderStatus::Created || $order->drop_off_reminded_at !== null) {
                    return null;
                }

                $order->forceFill(['drop_off_reminded_at' => now()])->save();

                return $order;
            });

            if ($order !== null && $order->customer->is_active && $order->customer->hasVerifiedEmail()) {
                $order->customer->notify(new DropOffReminder($order));
                $queued++;
            }
        }

        return $queued;
    }
}
