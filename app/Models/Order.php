<?php

namespace App\Models;

use App\Enums\DeliveryOutcome;
use App\Enums\MalaysianState;
use App\Enums\OrderStatus;
use App\Support\DeliveryProgress;
use App\Support\MailText;
use App\Support\Settings;
use App\Support\TrackingNumber;
use Carbon\CarbonImmutable;
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
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

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
 * @property string|null $receiver_email
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
 * @property int|null $estimated_rate_card_id
 * @property int|null $final_rate_card_id
 * @property int|null $driver_id
 * @property CarbonInterface|null $scheduled_for
 * @property int|null $route_position
 * @property CarbonInterface|null $route_date
 * @property CarbonInterface|null $drop_off_deadline
 * @property CarbonInterface|null $drop_off_reminded_at
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
    'receiver_name', 'receiver_phone', 'receiver_email',
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
            'route_position' => 'integer',
            'route_date' => 'date',
            'drop_off_deadline' => 'date',
            'drop_off_reminded_at' => 'datetime',
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
     * The rate card that priced the online estimate.
     *
     * @return BelongsTo<RateCard, $this>
     */
    public function estimatedRateCard(): BelongsTo
    {
        return $this->belongsTo(RateCard::class, 'estimated_rate_card_id');
    }

    /**
     * The rate card that set the final price when the parcel was weighed.
     *
     * @return BelongsTo<RateCard, $this>
     */
    public function finalRateCard(): BelongsTo
    {
        return $this->belongsTo(RateCard::class, 'final_rate_card_id');
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
     * Get where the parcel goes without the street, e.g. "Petaling Jaya 47300"
     * as My jobs heads each stop, for places that must not show the full
     * address, such as driver emails.
     *
     * The city is the customer's own text, so this is always one line of
     * plain text, without the characters that Markdown or an HTML table would
     * read as markup (MailText::plain()). New orders cannot have them
     * (StoreOrderRequest); this also covers older ones, and does not rely on
     * how the mail views were compiled.
     *
     * The postcode is joined to the town's last word by a no-break space, so
     * a narrow screen never leaves it alone on a line.
     */
    public function deliveryArea(): string
    {
        return Str::replaceLast(' ', "\u{00A0}", MailText::plain("{$this->city} {$this->postcode}"));
    }

    /**
     * Get the link in each receiver email to the page that stops them.
     *
     * The signature covers the path only, so the link works on whichever
     * host name serves the site.
     */
    public function stopReceiverEmailsUrl(): string
    {
        return url(URL::signedRoute('receiver-emails.show', $this, absolute: false));
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
            OrderStatus::DeliveryFailed => $this->failedAttemptsCount() < app(Settings::class)->maxFailedAttempts(),
            default => false,
        };
    }

    /**
     * Get the last Malaysian calendar day to drop the parcel off, while it is
     * waiting for drop-off. It is fixed when the order is placed, so a later
     * change to the limit never moves a date the customer has been given.
     */
    public function dropOffDeadline(): ?CarbonImmutable
    {
        if ($this->status !== OrderStatus::Created || $this->drop_off_deadline === null) {
            return null;
        }

        return CarbonImmutable::parse($this->drop_off_deadline->toDateString(), config()->string('kotak.timezone'));
    }

    /**
     * Get the day the customer can expect the parcel. While it is on a
     * driver's run, a delivery left over from an earlier day has joined
     * today's run, so the expected day is never in the past.
     */
    public function expectedDelivery(): ?CarbonImmutable
    {
        if ($this->scheduled_for === null) {
            return null;
        }

        return $this->status->isActiveJob()
            ? DeliveryProgress::runDay($this->scheduled_for)
            : CarbonImmutable::parse($this->scheduled_for->toDateString(), config()->string('kotak.timezone'));
    }

    /**
     * Get the drop-off deadline for an order placed at the given moment: the
     * order day in Malaysia plus the current limit. The nightly job cancels
     * the order once that day has ended.
     */
    public static function dropOffDeadlineFor(CarbonInterface $placedAt): CarbonImmutable
    {
        return $placedAt->toImmutable()
            ->setTimezone(config()->string('kotak.timezone'))
            ->startOfDay()
            ->addDays(app(Settings::class)->unclaimedOrderDays());
    }

    /**
     * Get the place of a delivery added to the driver's run for a day: after
     * every stop already placed on it (route_date), including the jobs
     * carried over from earlier days that the driver has put among that
     * day's stops.
     *
     * Call it inside a transaction: the run's stops stay locked until it
     * commits, so two deliveries added to the same run at the same moment
     * get a place each, one after the other, and neither can sort before a
     * stop already on the run.
     */
    public static function nextRoutePosition(User $driver, CarbonInterface $date): int
    {
        return (int) static::query()
            ->forDriver($driver)
            ->activeJobs()
            ->routedOn($date)
            ->lockForUpdate()
            ->max('route_position') + 1;
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
     * Scope a query to the driver's open deliveries for a Malaysian day, in
     * the order My jobs lists them. It is the one order of the stops: the
     * morning run sheet (App\Notifications\DriverRunSheet) emails it, MoveJob
     * changes it and App\Support\DeliveryProgress counts stops along it.
     *
     * A later day lists its own run in the driver's order. Today's list also
     * carries over the jobs left open on earlier days. Those not yet placed
     * on today's run (their route_date is an earlier day) come first, as they
     * are the oldest, in the order they had: by the run they were last on,
     * then place. So a job the driver put last yesterday is still behind
     * yesterday's other stops this morning. Then come the stops of today's
     * run in the driver's order. A move on today's list places the whole
     * list on today's run (MoveJob), so from then on a job carried over stays
     * wherever the driver puts it. An earlier day lists its own jobs still
     * open, which are on today's list now, in the same order.
     *
     * The day is compared by its date with today in Malaysia, so a day made
     * in another timezone, such as UTC midnight, still counts as today.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function jobListFor(Builder $query, User $driver, CarbonInterface $day): void
    {
        $query->forDriver($driver)->activeJobs();

        $today = today(config()->string('kotak.timezone'))->toImmutable();

        if ($day->toDateString() > $today->toDateString()) {
            $query->scheduledOn($day)->inRouteOrder();

            return;
        }

        if ($day->toDateString() === $today->toDateString()) {
            $query->scheduledBefore($today->addDay());
        } else {
            $query->scheduledOn($day);
        }

        // A range as in routedOn(), so a date stored with a time still matches.
        $onTodaysRun = 'route_date >= ? and route_date < ?';
        $todaysRun = [$today->toDateString(), $today->addDay()->toDateString()];

        // Every job has the day of its run since it was assigned (AssignDriver),
        // so the scheduled day only stands in for a job that never had one.
        $query->orderByRaw("case when {$onTodaysRun} then 1 else 0 end", $todaysRun)
            ->orderByRaw("case when {$onTodaysRun} then null else coalesce(route_date, scheduled_for) end", $todaysRun)
            ->inRouteOrder();
    }

    /**
     * Scope a query to the driver's order of the stops. Two stops with the
     * same place, such as two dispatches at the same moment, follow the
     * postcode, as My jobs listed them before drivers could change the
     * order, and then the id.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function inRouteOrder(Builder $query): void
    {
        $query->orderBy('route_position')
            ->orderBy('postcode')
            ->orderBy('id');
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
     * Scope a query to the stops placed on a run for the given day (route_date).
     *
     * A range like scheduledOn(), so a date stored with a time still matches.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function routedOn(Builder $query, CarbonInterface $date): void
    {
        $query->where('route_date', '>=', $date->toDateString())
            ->where('route_date', '<', $date->toImmutable()->addDay()->toDateString());
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
     * Scope a query to orders still waiting for drop-off after their deadline
     * day: the ones the nightly job cancels when it runs on the given day
     * (Malaysia), today by default.
     *
     * A plain comparison with the date string, as for scheduled_for, rather
     * than whereDate(), which would wrap the column in a function.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function unclaimed(Builder $query, ?CarbonInterface $on = null): void
    {
        $on ??= today(config()->string('kotak.timezone'));

        $query->where('status', OrderStatus::Created)
            ->where('drop_off_deadline', '<', $on->toDateString());
    }

    /**
     * Scope a query to orders waiting for drop-off whose customer is due a
     * reminder: the deadline is today or within the reminder lead time, and
     * nobody was reminded before. Counted in Malaysian calendar days, so an
     * order is reminded on the same day whatever time it was placed. Matches
     * nothing when reminders are off.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function dueForDropOffReminder(Builder $query): void
    {
        $lead = app(Settings::class)->dropOffReminderDaysBefore();

        if ($lead === 0) {
            $query->whereRaw('1 = 0');

            return;
        }

        $today = today(config()->string('kotak.timezone'));

        $query->where('status', OrderStatus::Created)
            ->whereNull('drop_off_reminded_at')
            ->where('drop_off_deadline', '>=', $today->toDateString())
            ->where('drop_off_deadline', '<', $today->addDays($lead + 1)->toDateString());
    }

    /**
     * Scope a query to orders the nightly job cancelled for never being
     * dropped off. That job is the only thing that cancels without an actor.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function expiredUnclaimed(Builder $query): void
    {
        $query->where('status', OrderStatus::Cancelled)
            ->whereNull('dropped_off_at')
            ->whereHas('statusEvents', fn (Builder $events) => $events
                ->where('to_status', OrderStatus::Cancelled)
                ->whereNull('actor_id'));
    }
}
