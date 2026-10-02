<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One step in an order's tracking history. Rows are append-only: they are
 * written by App\Services\OrderStatusService and never updated or deleted.
 *
 * @property int $id
 * @property int $order_id
 * @property OrderStatus|null $from_status
 * @property OrderStatus $to_status
 * @property string|null $note
 * @property int|null $actor_id
 * @property int|null $branch_id
 * @property CarbonInterface $created_at
 */
#[Fillable(['from_status', 'to_status', 'note', 'actor_id', 'branch_id'])]
class OrderStatusEvent extends Model
{
    /**
     * The history has no "updated at" column because rows never change.
     */
    public const UPDATED_AT = null;

    /**
     * Bootstrap the model and its traits.
     */
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Order status events are append-only.'));
        static::deleting(fn () => throw new LogicException('Order status events are append-only.'));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_status' => OrderStatus::class,
            'to_status' => OrderStatus::class,
        ];
    }

    /**
     * The order this event belongs to.
     *
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * The user who made the change, or null for automated changes.
     *
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * The branch where the change happened, if any.
     *
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
