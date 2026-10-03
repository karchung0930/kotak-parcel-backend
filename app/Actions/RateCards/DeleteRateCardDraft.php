<?php

namespace App\Actions\RateCards;

use App\Models\RateCard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteRateCardDraft
{
    /**
     * Delete a draft with its zones, routes and bands. Published cards are
     * kept for good, as orders point at the card that priced them.
     *
     * @throws ValidationException when the card is not a draft or priced an order
     */
    public function handle(RateCard $card): void
    {
        DB::transaction(function () use ($card): void {
            $card = $card->freshLocked();

            if (! $card->isDraft()) {
                throw ValidationException::withMessages(['card' => 'Only drafts can be deleted.']);
            }

            if ($card->hasPricedOrders()) {
                throw ValidationException::withMessages(['card' => 'These rates priced an order, so they are kept.']);
            }

            $card->delete();
        });
    }
}
