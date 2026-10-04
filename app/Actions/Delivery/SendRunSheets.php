<?php

namespace App\Actions\Delivery;

use App\Enums\Role;
use App\Models\User;
use App\Notifications\DriverRunSheet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Throwable;

class SendRunSheets
{
    /**
     * Email each driver today's run sheet, and return how many were queued.
     *
     * A driver gets one when they have jobs on today's My jobs list (today's
     * and any left open on earlier days), and only with an active account
     * and a verified email address (User::canBeEmailed()). The email reads
     * the list again when it is sent (DriverRunSheet).
     *
     * A cache key per driver and day makes it once a day, however often the
     * command runs. A driver whose email cannot be queued gets the key back,
     * so a later run the same day can still send it, and the others still
     * get theirs.
     */
    public function handle(): int
    {
        $today = today(config()->string('kotak.timezone'))->toImmutable();
        $queued = 0;

        $drivers = User::query()
            ->withRole(Role::Driver)
            ->emailable()
            ->whereHas('assignedOrders', fn (Builder $orders) => $orders->activeJobs()->scheduledBefore($today->addDay()));

        foreach ($drivers->lazyById() as $driver) {
            $key = "kotak.run-sheet.{$today->toDateString()}.{$driver->id}";

            // Kept until the day after, past any run that could still count as today.
            if (! Cache::add($key, true, $today->addDays(2))) {
                continue;
            }

            try {
                $driver->notify(new DriverRunSheet($today));
            } catch (Throwable $e) {
                Cache::forget($key);
                report($e);

                continue;
            }

            $queued++;
        }

        return $queued;
    }
}
