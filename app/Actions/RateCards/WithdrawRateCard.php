<?php

namespace App\Actions\RateCards;

use App\Enums\RateCardStatus;
use App\Models\RateCard;
use App\Support\RateCards;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WithdrawRateCard
{
    /**
     * Create a new action instance.
     */
    public function __construct(
        private RateCards $rateCards,
    ) {}

    /**
     * Take a scheduled card back to a draft before it takes effect, so it
     * can be changed or deleted. Once a card is in effect it has priced
     * orders and stays published.
     *
     * @throws ValidationException when the card is not scheduled (any more) or has priced an order
     */
    public function handle(RateCard $card): RateCard
    {
        $card = DB::transaction(function () use ($card): RateCard {
            $card = $card->freshLocked();

            if (! $card->isScheduled()) {
                throw ValidationException::withMessages([
                    'card' => 'Only scheduled rates can be withdrawn, before they take effect.',
                ]);
            }

            if ($card->hasPricedOrders()) {
                throw ValidationException::withMessages(['card' => 'These rates priced an order, so they are kept.']);
            }

            $card->forceFill([
                'status' => RateCardStatus::Draft,
                'effective_from' => null,
                'published_by' => null,
                'published_at' => null,
            ])->save();

            return $card;
        });

        $this->rateCards->refresh();

        return $card;
    }
}
