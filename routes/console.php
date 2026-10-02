<?php

use App\Actions\Orders\ExpireUnclaimedOrders;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('orders:expire-unclaimed', function (ExpireUnclaimedOrders $expire) {
    $this->info("Cancelled {$expire->handle()} unclaimed order(s).");
})->purpose('Cancel orders that were never dropped off at a branch');

// Runs at midnight Malaysia time.
Schedule::command('orders:expire-unclaimed')
    ->daily()
    ->timezone(config()->string('kotak.timezone'))
    ->withoutOverlapping();
