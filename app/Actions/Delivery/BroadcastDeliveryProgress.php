<?php

namespace App\Actions\Delivery;

use App\Events\DeliveryProgressUpdated;
use App\Models\Order;
use App\Models\User;
use App\Support\DeliveryProgress;
use Carbon\CarbonInterface;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

class BroadcastDeliveryProgress
{
    /**
     * How long the last numbers sent to a parcel are remembered: a run lasts a day.
     */
    private const REMEMBER_HOURS = 48;

    /**
     * Create a new action instance.
     */
    public function __construct(
        private DeliveryProgress $progress,
    ) {}

    /**
     * Work out the stops of the driver's run for a day again, and send each
     * parcel whose stop or status changed its new numbers on its own
     * channels (DeliveryProgressUpdated). Parcels whose numbers stayed the
     * same hear nothing.
     *
     * The parcel that changed is checked too, as it may have just left the
     * run (delivered, failed or handed to another driver): it then hears its
     * new status without a stop.
     *
     * The numbers are worked out when this runs, under a lock per driver, so
     * the last message each parcel gets always matches the database, even
     * when changes come in quick succession.
     *
     * @throws LockTimeoutException
     */
    public function handle(User $driver, CarbonInterface $date, ?Order $changed = null): void
    {
        Cache::lock("delivery-progress:driver:{$driver->id}", 10)->block(5, function () use ($driver, $date, $changed): void {
            $onTheVan = $this->progress->onTheVan($driver, $date)->values();

            $messages = $onTheVan->mapWithKeys(fn (Order $order, int $index): array => [
                $order->id => DeliveryProgressUpdated::for($order, DeliveryProgress::stop($index)),
            ])->all();

            // First, as no later run includes it once it has left this one.
            if ($changed !== null && ! array_key_exists($changed->id, $messages)) {
                $messages = [$changed->id => DeliveryProgressUpdated::for($changed, null)] + $messages;
            }

            $this->sendChanged($messages);
        });
    }

    /**
     * Send the messages whose numbers differ from the last ones sent to their parcel.
     *
     * Only numbers Reverb took are remembered. When it refuses a message or
     * cannot be reached, the error is reported and the rest wait, as they
     * would most likely fail too: the next time the run is worked out, every
     * parcel not remembered is sent its numbers, even if they did not change.
     *
     * @param  array<int, DeliveryProgressUpdated>  $messages  keyed by order id
     */
    private function sendChanged(array $messages): void
    {
        if ($messages === []) {
            return;
        }

        $key = fn (int $id): string => "delivery-progress:order:{$id}";
        $sent = Cache::many(array_map($key, array_keys($messages)));
        $remember = [];

        foreach ($messages as $id => $message) {
            if (($sent[$key($id)] ?? null) === $message->progress) {
                continue;
            }

            try {
                event($message);
                $remember[$key($id)] = $message->progress;
            } catch (BroadcastException $e) {
                report($e);

                break;
            }
        }

        if ($remember !== []) {
            Cache::putMany($remember, now()->addHours(self::REMEMBER_HOURS));
        }
    }
}
