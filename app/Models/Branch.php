<?php

namespace App\Models;

use App\Enums\MalaysianState;
use App\Support\Geo;
use App\Support\MailText;
use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $address
 * @property string $city
 * @property MalaysianState $state
 * @property string $postcode
 * @property string $phone
 * @property string $latitude
 * @property string $longitude
 * @property string $opening_hours
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'code', 'name', 'address', 'city', 'state', 'postcode', 'phone',
    'latitude', 'longitude', 'opening_hours', 'is_active',
])]
class Branch extends Model
{
    /** @use HasFactory<BranchFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => MalaysianState::class,
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The staff members working at the branch.
     *
     * @return HasMany<User, $this>
     */
    public function staff(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * The orders dropped off (or to be dropped off) at the branch.
     *
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Get the address on one line as emails give it, e.g. "12, Jalan SS 2/67,
     * SS 2, 47300 Petaling Jaya", as plain text (MailText::plain()). The
     * postcode and town are joined by no-break spaces, so a narrow screen
     * never leaves the town's last word alone.
     */
    public function mailAddress(): string
    {
        return MailText::plain($this->address).', '.str_replace(' ', "\u{00A0}", MailText::plain("{$this->postcode} {$this->city}"));
    }

    /**
     * Get the distance in kilometres from the given coordinates to the branch.
     */
    public function distanceKmFrom(float $latitude, float $longitude): float
    {
        return Geo::distanceKm($latitude, $longitude, (float) $this->latitude, (float) $this->longitude);
    }

    /**
     * Find the active branch nearest to the given coordinates.
     *
     * The website does this in the browser so the customer's location never
     * leaves their device; this is the server-side equivalent.
     */
    public static function nearestTo(float $latitude, float $longitude): ?self
    {
        return static::query()
            ->active()
            ->get()
            ->sortBy(fn (self $branch): float => $branch->distanceKmFrom($latitude, $longitude))
            ->first();
    }

    /**
     * Scope a query to branches that accept parcels.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
