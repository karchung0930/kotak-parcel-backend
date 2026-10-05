<?php

namespace App\Actions\Delivery;

use App\Enums\OrderStatus;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

class RefreshDeliveryProgress
{
    /**
     * Create a new action instance.
     */
    public function __construct(
        private BroadcastDeliveryProgress $broadcast,
    ) {}

    /**
     * Work out every driver's run for today again, send each parcel on the
     * van whose stop changed its new numbers, and return how many runs were
     * worked out.
     *
     * It runs just after midnight. The jobs left open yesterday then join
     * today's list, ahead of the stops placed on today's run, so a parcel
     * collected early for today now has more stops before it than its page
     * says. Parcels whose numbers stayed the same hear nothing, as
     * BroadcastDeliveryProgress remembers what each one was last sent.
     *
     * Only drivers with a parcel on the van for today or an earlier day are
     * looked at, as no other parcel has a stop. A run that cannot be worked
     * out is reported, and the others still are.
     */
    public function handle(): int
    {
        $today = today(config()->string('kotak.timezone'))->toImmutable();
        $refreshed = 0;

        $drivers = User::query()
            ->withRole(Role::Driver)
            ->whereHas('assignedOrders', fn (Builder $orders) => $orders
                ->where('status', OrderStatus::PickedUp)
                ->scheduledBefore($today->addDay()));

        foreach ($drivers->lazyById() as $driver) {
            try {
                $this->broadcast->handle($driver, $today);
            } catch (Throwable $e) {
                report($e);

                continue;
            }

            $refreshed++;
        }

        return $refreshed;
    }
}
