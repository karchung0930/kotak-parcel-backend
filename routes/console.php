<?php

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

Artisan::command('rates:prune-imports', function (PruneRateImportFiles $prune) {
    $this->info("Deleted {$prune->handle()} rate import file(s).");
})->purpose('Delete uploaded rate spreadsheets older than a week');

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

// Runs at 3am Malaysia time, when nobody is likely to be importing rates.
Schedule::command('rates:prune-imports')
    ->dailyAt('03:00')
    ->timezone(config()->string('kotak.timezone'))
    ->withoutOverlapping();
