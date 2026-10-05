<?php

use App\Broadcasting\OrderChannel;
use Illuminate\Support\Facades\Broadcast;

// Live delivery progress (App\Events\DeliveryProgressUpdated). A customer's
// own order page listens on this private channel; the public tracking page
// uses a channel whose name cannot be guessed instead
// (App\Support\DeliveryProgress::publicChannel()), which needs no sign-in.
Broadcast::channel('orders.{order}', OrderChannel::class);
