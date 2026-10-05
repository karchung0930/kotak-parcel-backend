<?php

namespace App\Events;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A driver changed the order of the stops on their list for today.
 * Dispatched only after the change is committed.
 */
class DeliveryRunReordered implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param  User  $driver  the driver whose run it is
     * @param  CarbonInterface  $date  the day of the reordered run: today
     */
    public function __construct(
        public User $driver,
        public CarbonInterface $date,
    ) {}
}
