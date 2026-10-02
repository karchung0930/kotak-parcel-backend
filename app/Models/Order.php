<?php

namespace App\Models;

use App\Enums\DeliveryOutcome;
use App\Enums\MalaysianState;
use App\Enums\OrderStatus;
use App\Support\TrackingNumber;
use Carbon\CarbonInterface;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A parcel delivery order. Its status only ever changes through
 * App\Services\OrderStatusService, which also records the history.
 *
 * @property int $id
 * @property string $tracking_number
 * @property int $customer_id
 * @property int $branch_id
 * @property OrderStatus $status
 * @property string $sender_name
 * @property string $sender_phone
 * @property string $receiver_name
 * @property string $receiver_phone
 * @property string $address_line1
 * @property string|null $address_line2
 * @property string $city
 * @property MalaysianState $state
 * @property string $postcode
 * @property string $item_name
 * @property int $declared_weight_g
 * @property int $length_cm
 * @property int $width_cm
 * @property int $height_cm
 * @property int|null $measured_weight_g
 * @property int $chargeable_weight_g
 * @property int $estimated_price_sen
 * @property int|null $final_price_sen
 * @property int|null $driver_id
 * @property CarbonInterface|null $scheduled_for
 * @property CarbonInterface|null $dropped_off_at
 * @property CarbonInterface|null $paid_at
 * @property CarbonInterface|null $delivered_at
 * @property CarbonInterface|null $cancelled_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read string $formatted_tracking_number
 */
#[Fillable([
    'branch_id',
    'receiver_name', 'receiver_phone',
    'address_line1', 'address_line2', 'city', 'state', 'postcode',
    'item_name', 'declared_weight_g', 'length_cm', 'width_cm', 'height_cm',
])]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'created',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'state' => MalaysianState::class,
            'declared_weight_g' => 'integer',
            'length_cm' => 'integer',
            'width_cm' => 'integer',
            'height_cm' => 'integer',
            'measured_weight_g' => 'integer',
            'chargeable_weight_g' => 'integer',
            'estimated_price_sen' => 'integer',
            'final_price_sen' => 'integer',
            'scheduled_for' => 'date',
            'dropped_off_at' => 'datetime',
            'paid_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * The customer who placed the order.
     *
     * @return BelongsTo<User, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    /**
     * The branch where the parcel is (or will be) dropped off.
     *
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * The driver the delivery is assigned to.
     *
     * @return BelongsTo<User, $this>
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    /**
     * The tracking history, oldest first (use latestStatusEvent() for the newest).
     *
     * @return HasMany<OrderStatusEvent, $this>
     */
    public function statusEvents(): HasMany
    {
        return $this->hasMany(OrderStatusEvent::class)->orderBy('id');
    }

    /**
     * The most recent tracking history entry.
     *
     * @return HasOne<OrderStatusEvent, $this>
     */
    public function latestStatusEvent(): HasOne
    {
        return $this->hasOne(OrderStatusEvent::class)->latestOfMany();
    }

    /**
     * The payment taken at the branch.
     *
     * @return HasOne<Payment, $this>
     */
    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }

    /**
     * Every delivery attempt made by drivers.
     *
     * @return HasMany<DeliveryAttempt, $this>
     */
    public function deliveryAttempts(): HasMany
    {
        return $this->hasMany(DeliveryAttempt::class);
    }

    /**
     * The failed delivery attempts (use withCount('failedAttempts') for lists).
     *
     * @return HasMany<DeliveryAttempt, $this>
     */
    public function failedAttempts(): HasMany
    {
        return $this->deliveryAttempts()->where('outcome', DeliveryOutcome::Failed);
    }

    /**
     * The most recent delivery attempt.
     *
     * @return HasOne<DeliveryAttempt, $this>
     */
    public function latestAttempt(): HasOne
    {
        return $this->hasOne(DeliveryAttempt::class)->latestOfMany();
    }

    /**
     * Get the tracking number formatted for display, e.g. "KT-7Q4M92XD".
     *
     * @return Attribute<string, never>
     */
    protected function formattedTrackingNumber(): Attribute
    {
        return Attribute::get(fn (): string => TrackingNumber::format($this->tracking_number));
    }

    /**
     * Determine if the order was placed by the given user.
     */
    public function isOwnedBy(User $user): bool
    {
        return $this->customer()->is($user);
    }

    /**
     * Determine if the delivery is assigned to the given driver.
     */
    public function isAssignedTo(User $driver): bool
    {
        return $this->driver()->is($driver);
    }

    /**
     * Get the number of failed delivery attempts, using a loaded count when available.
     */
    public function failedAttemptsCount(): int
    {
        if (array_key_exists('failed_attempts_count', $this->attributes)) {
            return (int) $this->attributes['failed_attempts_count'];
        }

        return $this->failedAttempts()->count();
    }

    /**
     * Determine if the order can be assigned to a driver: first dispatch, a
     * reschedule after a failed delivery while attempts remain, or a
     * reassignment before the driver collects the parcel.
     */
    public function canBeAssigned(): bool
    {
        return match ($this->status) {
            OrderStatus::Paid, OrderStatus::Assigned => true,
            OrderStatus::DeliveryFailed => $this->failedAttemptsCount() < config()->integer('kotak.max_failed_attempts'),
            default => false,
        };
    }

    /**
     * Determine if the delivery is still open after its scheduled day (in Malaysia).
     */
    public function isOverdue(): bool
    {
        return $this->status->isActiveJob()
            && $this->scheduled_for !== null
            && $this->scheduled_for->toDateString() < today(config()->string('kotak.timezone'))->toDateString();
    }

    /**
     * Re-read the order and hold a row lock on it until the current transaction ends.
     */
    public function freshLocked(): static
    {
        return static::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();
    }

    /**
     * Scope a query to orders placed by the given customer.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function forCustomer(Builder $query, User $customer): void
    {
        $query->where('customer_id', $customer->id);
    }

    /**
     * Scope a query to orders assigned to the given driver.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function forDriver(Builder $query, User $driver): void
    {
        $query->where('driver_id', $driver->id);
    }

    /**
     * Scope a query to paid orders waiting for a driver.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function awaitingDispatch(Builder $query): void
    {
        $query->where('status', OrderStatus::Paid);
    }

    /**
     * Scope a query to failed deliveries waiting for an admin to reschedule or return them.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function failedAwaitingAction(Builder $query): void
    {
        $query->where('status', OrderStatus::DeliveryFailed);
    }

    /**
     * Scope a query to deliveries a driver still has to complete.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function activeJobs(Builder $query): void
    {
        $query->whereIn('status', OrderStatus::activeJobs());
    }

    /**
     * Scope a query to deliveries scheduled on the given day.
     *
     * A plain range rather than whereDate(), which would wrap the column in a
     * function and stop the (driver_id, scheduled_for) index from being used.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function scheduledOn(Builder $query, CarbonInterface $date): void
    {
        $query->where('scheduled_for', '>=', $date->toDateString())
            ->where('scheduled_for', '<', $date->toImmutable()->addDay()->toDateString());
    }

    /**
     * Scope a query to deliveries scheduled before the given day.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function scheduledBefore(Builder $query, CarbonInterface $date): void
    {
        $query->where('scheduled_for', '<', $date->toDateString());
    }

    /**
     * Scope a query to the order with the given tracking number, in any accepted format.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function byTrackingNumber(Builder $query, ?string $trackingNumber): void
    {
        // An unrecognisable number matches nothing.
        $query->where('tracking_number', TrackingNumber::normalize($trackingNumber) ?? '');
    }

    /**
     * Scope a query to a free-text search by tracking number or sender / receiver name.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function search(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        if ($trackingNumber = TrackingNumber::normalize($term)) {
            $query->where('tracking_number', $trackingNumber);

            return;
        }

        $query->where(fn (Builder $query) => $query
            ->where('receiver_name', 'like', "%{$term}%")
            ->orWhere('sender_name', 'like', "%{$term}%"));
    }

    /**
     * Scope a query to orders that were never dropped off within the allowed time.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function unclaimed(Builder $query): void
    {
        $query->where('status', OrderStatus::Created)
            ->where('created_at', '<=', now()->subDays(config()->integer('kotak.unclaimed_order_days')));
    }
}
