<?php

use App\Actions\Delivery\RefreshDeliveryProgress;
use App\Actions\Delivery\SendRunSheets;
use App\Actions\Orders\ExpireUnclaimedOrders;
use App\Actions\Orders\SendDropOffReminders;
use App\Actions\RateImports\PruneRateImportFiles;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('orders:expire-unclaimed', function (ExpireUnclaimedOrders $expire) {
    $this->info("Cancelled {$expire->handle()} unclaimed order(s).");
})->purpose('Cancel orders that were never dropped off at a branch');

Artisan::command('orders:remind-unclaimed', function (SendDropOffReminders $remind) {
    $this->info("Queued {$remind->handle()} drop-off reminder(s).");
})->purpose('Remind customers to drop off orders that will soon be cancelled');

Artisan::command('drivers:send-run-sheets', function (SendRunSheets $send) {
    $this->info("Queued {$send->handle()} run sheet(s).");
})->purpose("Email each driver today's deliveries");

Artisan::command('rates:prune-imports', function (PruneRateImportFiles $prune) {
    $this->info("Deleted {$prune->handle()} rate import file(s).");
})->purpose('Delete uploaded rate spreadsheets older than a week');

Artisan::command('deliveries:refresh-progress', function (RefreshDeliveryProgress $refresh) {
    $this->info("Refreshed the stops of {$refresh->handle()} delivery run(s).");
})->purpose("Send parcels out for delivery their stop counts on today's runs");

// Runs at midnight Malaysia time.
Schedule::command('orders:expire-unclaimed')
    ->daily()
    ->timezone(config()->string('kotak.timezone'))
    ->withoutOverlapping();

// Runs at 9am Malaysia time, when branches are opening.
Schedule::command('orders:remind-unclaimed')
    ->dailyAt('09:00')
    ->timezone(config()->string('kotak.timezone'))
    ->withoutOverlapping();

// Runs at 7am Malaysia time, before drivers collect their parcels.
Schedule::command('drivers:send-run-sheets')
    ->dailyAt('07:00')
    ->timezone(config()->string('kotak.timezone'))
    ->withoutOverlapping();

// Runs at 3am Malaysia time, when nobody is likely to be importing rates.
Schedule::command('rates:prune-imports')
    ->dailyAt('03:00')
    ->timezone(config()->string('kotak.timezone'))
    ->withoutOverlapping();

// Runs a minute after midnight Malaysia time, once the jobs left open
// yesterday have joined today's lists.
Schedule::command('deliveries:refresh-progress')
    ->dailyAt('00:01')
    ->timezone(config()->string('kotak.timezone'))
    ->withoutOverlapping();
