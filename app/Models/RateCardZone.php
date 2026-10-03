<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A group of states priced alike, e.g. "Sabah & Labuan". On a published
 * card every state is in exactly one zone.
 *
 * @property int $id
 * @property int $rate_card_id
 * @property string $code
 * @property string $name
 * @property list<string> $states App\Enums\MalaysianState values
 */
#[Fillable(['code', 'name', 'states'])]
#[WithoutTimestamps]
class RateCardZone extends Model
{
    /**
     * The longest code the column takes.
     */
    public const CODE_LENGTH = 64;

    /**
     * Make a zone's code from its name: "Sabah & Labuan" → "sabah-labuan".
     * Letters with accents become plain Latin ones, and the code is cut to
     * fit the column. A name with no Latin letters or digits ("东马") has no
     * code, so UpdateRateCardRequest refuses it.
     */
    public static function codeFor(string $name): string
    {
        return rtrim(Str::substr(Str::slug($name), 0, self::CODE_LENGTH), '-');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'states' => 'array',
        ];
    }

    /**
     * The rate card the zone belongs to.
     *
     * @return BelongsTo<RateCard, $this>
     */
    public function rateCard(): BelongsTo
    {
        return $this->belongsTo(RateCard::class);
    }
}
