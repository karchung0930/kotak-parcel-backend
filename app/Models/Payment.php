<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use Carbon\CarbonInterface;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A payment taken at the branch counter. Created only by App\Actions\Payments\RecordPayment.
 *
 * @property int $id
 * @property int $order_id
 * @property int $amount_sen
 * @property PaymentMethod $method
 * @property string|null $reference
 * @property string $receipt_number
 * @property int $received_by
 * @property int $branch_id
 * @property CarbonInterface $paid_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable(['method', 'reference'])]
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_sen' => 'integer',
            'method' => PaymentMethod::class,
            'paid_at' => 'datetime',
        ];
    }

    /**
     * Build the receipt number for an order paid at the given time, e.g. "RCPT-20260929-7Q4M92XD".
     *
     * The date is the Malaysian business day, and the number is unique because
     * each order is paid once and has a unique tracking number.
     */
    public static function receiptNumberFor(Order $order, CarbonInterface $paidAt): string
    {
        $businessDay = $paidAt->toImmutable()->setTimezone(config()->string('kotak.timezone'));

        return 'RCPT-'.$businessDay->format('Ymd').'-'.substr($order->tracking_number, 2);
    }

    /**
     * The order that was paid for.
     *
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * The staff member who took the payment.
     *
     * @return BelongsTo<User, $this>
     */
    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /**
     * The branch where the payment was taken.
     *
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
