<?php

namespace App\Actions\Delivery;

use App\Enums\DeliveryOutcome;
use App\Enums\OrderStatus;
use App\Exceptions\InvalidStatusTransition;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderStatusService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class RecordDeliverySuccess
{
    /**
     * Create a new action instance.
     */
    public function __construct(
        private OrderStatusService $statuses,
    ) {}

    /**
     * Record a successful delivery with the recipient's name and a proof of delivery photo.
     *
     * The photo is kept on the private "local" disk and served only through an
     * authorised route, never from a public URL.
     *
     * @throws AuthorizationException
     * @throws InvalidStatusTransition
     */
    public function handle(Order $order, User $driver, string $recipientName, UploadedFile $photo): Order
    {
        $path = $photo->storeAs("pod/{$order->id}", Str::uuid().'.'.($photo->extension() ?: 'jpg'), 'local');

        if ($path === false) {
            throw new RuntimeException('Unable to store the proof of delivery photo.');
        }

        try {
            return DB::transaction(function () use ($order, $driver, $recipientName, $path): Order {
                $order = $order->freshLocked();

                if (! $order->isAssignedTo($driver)) {
                    throw new AuthorizationException('This delivery is not assigned to you.');
                }

                $order->deliveryAttempts()->forceCreate([
                    'driver_id' => $driver->id,
                    'outcome' => DeliveryOutcome::Delivered,
                    'recipient_name' => trim($recipientName),
                    'photo_path' => $path,
                    'attempted_at' => now(),
                ]);

                return $this->statuses->transition($order, OrderStatus::Delivered, $driver);
            });
        } catch (Throwable $e) {
            // Nothing was recorded, so do not keep an orphaned photo.
            Storage::disk('local')->delete($path);

            throw $e;
        }
    }
}
