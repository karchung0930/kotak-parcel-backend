<?php

namespace App\Models;

use App\Enums\RateCardPhase;
use App\Enums\RateCardStatus;
use App\Support\RateCards;
use Carbon\CarbonInterface;
use Database\Factories\RateCardFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One version of the prices: zones of states, the routes between them and
 * each route's weight bands. Drafts are edited freely. A published card
 * never changes: new prices go into a draft copy, which takes over once it
 * is published and takes effect. App\Support\RateCards picks the card in
 * effect and caches the published ones.
 *
 * @property int $id
 * @property string $name
 * @property RateCardStatus $status
 * @property CarbonInterface|null $effective_from
 * @property int $volumetric_divisor
 * @property string|null $notes
 * @property int|null $created_by
 * @property int|null $published_by
 * @property CarbonInterface|null $published_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable(['name', 'volumetric_divisor', 'notes'])]
class RateCard extends Model
{
    /** @use HasFactory<RateCardFactory> */
    use HasFactory;

    /**
     * The divisor a blank draft starts with: volumetric kg = L x W x H (cm) / 5000.
     */
    public const DEFAULT_VOLUMETRIC_DIVISOR = 5000;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'volumetric_divisor' => self::DEFAULT_VOLUMETRIC_DIVISOR,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RateCardStatus::class,
            'effective_from' => 'datetime',
            'volumetric_divisor' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    /**
     * The zones, in the order the admin listed them.
     *
     * @return HasMany<RateCardZone, $this>
     */
    public function zones(): HasMany
    {
        return $this->hasMany(RateCardZone::class)->orderBy('id');
    }

    /**
     * The routes between zones (load "routes.bands" for their prices).
     *
     * @return HasMany<RateCardRoute, $this>
     */
    public function routes(): HasMany
    {
        return $this->hasMany(RateCardRoute::class)->orderBy('id');
    }

    /**
     * The admin who created the card (none for the first card, which a migration made).
     *
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The admin who published the card.
     *
     * @return BelongsTo<User, $this>
     */
    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    /**
     * Determine if the card is a draft, which can still be edited or deleted.
     */
    public function isDraft(): bool
    {
        return $this->status === RateCardStatus::Draft;
    }

    /**
     * Determine if the card is published but has not taken effect yet, so
     * it can still be withdrawn.
     */
    public function isScheduled(): bool
    {
        return $this->status === RateCardStatus::Published
            && $this->effective_from !== null
            && $this->effective_from->isFuture();
    }

    /**
     * Get where the card stands: a draft, scheduled, the current card or a past one.
     */
    public function phase(): RateCardPhase
    {
        return match (true) {
            $this->isDraft() => RateCardPhase::Draft,
            $this->isScheduled() => RateCardPhase::Scheduled,
            app(RateCards::class)->current()->id === $this->id => RateCardPhase::Current,
            default => RateCardPhase::Past,
        };
    }

    /**
     * Determine if any order was priced with the card. A draft never prices
     * anything, but one withdrawn just as it took effect might have; such a
     * card is kept as it is, so each order's record of its prices stays true.
     */
    public function hasPricedOrders(): bool
    {
        return Order::query()
            ->where(fn (Builder $orders) => $orders
                ->where('estimated_rate_card_id', $this->id)
                ->orWhere('final_rate_card_id', $this->id))
            ->exists();
    }

    /**
     * Re-read the card and hold a row lock on it until the current transaction ends.
     */
    public function freshLocked(): static
    {
        return static::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();
    }

    /**
     * Scope a query to published cards: scheduled, current and past.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function published(Builder $query): void
    {
        $query->where('status', RateCardStatus::Published);
    }

    /**
     * Scope a query to the order admins see versions in: drafts first (the
     * latest edited on top), then published cards from the last to take effect.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function newestFirst(Builder $query): void
    {
        $query->orderByRaw('case when status = ? then 0 else 1 end', [RateCardStatus::Draft->value])
            ->orderByDesc('effective_from')
            ->orderByDesc('updated_at')
            ->orderByDesc('id');
    }
}
