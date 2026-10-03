<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The prices from one zone to another, or within one zone: its weight
 * bands, and a price for each started kg above the highest band.
 *
 * @property int $id
 * @property int $rate_card_id
 * @property int $origin_zone_id
 * @property int $destination_zone_id
 * @property int|null $extra_kg_sen
 */
#[Fillable(['origin_zone_id', 'destination_zone_id', 'extra_kg_sen'])]
#[WithoutTimestamps]
class RateCardRoute extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'extra_kg_sen' => 'integer',
        ];
    }

    /**
     * The rate card the route belongs to.
     *
     * @return BelongsTo<RateCard, $this>
     */
    public function rateCard(): BelongsTo
    {
        return $this->belongsTo(RateCard::class);
    }

    /**
     * The zone parcels are dropped off in.
     *
     * @return BelongsTo<RateCardZone, $this>
     */
    public function originZone(): BelongsTo
    {
        return $this->belongsTo(RateCardZone::class, 'origin_zone_id');
    }

    /**
     * The zone parcels are delivered to.
     *
     * @return BelongsTo<RateCardZone, $this>
     */
    public function destinationZone(): BelongsTo
    {
        return $this->belongsTo(RateCardZone::class, 'destination_zone_id');
    }

    /**
     * The weight bands, lightest first.
     *
     * @return HasMany<RateCardBand, $this>
     */
    public function bands(): HasMany
    {
        return $this->hasMany(RateCardBand::class)->orderBy('max_weight_g');
    }
}
