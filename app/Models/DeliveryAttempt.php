<?php

namespace App\Models;

use App\Enums\DeliveryFailureReason;
use App\Enums\DeliveryOutcome;
use Carbon\CarbonInterface;
use Database\Factories\DeliveryAttemptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One delivery attempt by a driver: either delivered with proof, or failed with a reason.
 *
 * @property int $id
 * @property int $order_id
 * @property int $driver_id
 * @property DeliveryOutcome $outcome
 * @property string|null $recipient_name
 * @property string|null $photo_path
 * @property DeliveryFailureReason|null $failure_reason
 * @property string|null $note
 * @property CarbonInterface $attempted_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable(['recipient_name', 'failure_reason', 'note'])]
class DeliveryAttempt extends Model
{
    /** @use HasFactory<DeliveryAttemptFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'outcome' => DeliveryOutcome::class,
            'failure_reason' => DeliveryFailureReason::class,
            'attempted_at' => 'datetime',
        ];
    }

    /**
     * The order that was being delivered.
     *
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * The driver who made the attempt.
     *
     * @return BelongsTo<User, $this>
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }
}
