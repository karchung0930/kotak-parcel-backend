<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The price of a route's parcels up to a weight, e.g. up to 2 kg for RM 13.00.
 *
 * @property int $id
 * @property int $rate_card_route_id
 * @property int $max_weight_g
 * @property int $price_sen
 */
#[Fillable(['max_weight_g', 'price_sen'])]
#[WithoutTimestamps]
class RateCardBand extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'max_weight_g' => 'integer',
            'price_sen' => 'integer',
        ];
    }

    /**
     * The route the band belongs to.
     *
     * @return BelongsTo<RateCardRoute, $this>
     */
    public function route(): BelongsTo
    {
        return $this->belongsTo(RateCardRoute::class, 'rate_card_route_id');
    }
}
