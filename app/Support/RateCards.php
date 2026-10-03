<?php

namespace App\Support;

use App\Models\RateCard;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * The published rate cards, and the one that prices orders at a moment.
 *
 * Every published card is cached as one list of price lists in their array
 * form, oldest first. The card in effect is picked when it is asked for, so
 * a scheduled card takes over at its time without the cache being touched.
 *
 * Publishing or withdrawing a card writes the list to the cache again once
 * the change has committed (refresh()). Readers only ever add() a copy, so
 * one that read the table just before a change cannot replace the new list.
 * The container keeps one instance per request or queued job, so the list
 * is read at most once in each.
 *
 * @phpstan-import-type Card from PriceList
 */
class RateCards
{
    /**
     * The cache key holding every published card.
     */
    public const CACHE_KEY = 'kotak.rate-cards';

    /**
     * How long the cached list is kept, in seconds. Publishing and
     * withdrawing write it straight away, so this only bounds how long a
     * change made behind the cache's back can go unseen.
     */
    public const CACHE_SECONDS = 86400;

    /**
     * The published cards for this request, oldest first, once loaded.
     *
     * @var list<PriceList>|null
     */
    private ?array $published = null;

    /**
     * Get the card in effect now (or at the given moment): the published
     * card that took effect last.
     *
     * @throws RuntimeException when no card has ever been published
     */
    public function current(?CarbonInterface $at = null): PriceList
    {
        $at ??= now();
        $cards = $this->published();
        $current = null;

        foreach ($cards as $card) {
            if ($card->effectiveFrom !== null && $card->effectiveFrom->greaterThan($at)) {
                break;
            }

            $current = $card;
        }

        // Only a clock moved back before the first card took effect (as tests
        // and the demo seeder do) finds none; the first card applies then.
        return $current ?? $cards[0] ?? throw new RuntimeException('No rate card has been published.');
    }

    /**
     * Get the next card to take effect after now (or the given moment), so
     * customers can be told about new prices before they apply.
     */
    public function upcoming(?CarbonInterface $at = null): ?PriceList
    {
        $at ??= now();

        foreach ($this->published() as $card) {
            if ($card->effectiveFrom !== null && $card->effectiveFrom->greaterThan($at)) {
                return $card;
            }
        }

        return null;
    }

    /**
     * Get a published card by its id.
     */
    public function find(int $id): ?PriceList
    {
        foreach ($this->published() as $card) {
            if ($card->id === $id) {
                return $card;
            }
        }

        return null;
    }

    /**
     * Get every published card, oldest first: scheduled, current and past.
     *
     * @return list<PriceList>
     */
    public function published(): array
    {
        if ($this->published !== null) {
            return $this->published;
        }

        $cards = Cache::get(self::CACHE_KEY);

        if (! is_array($cards)) {
            $cards = $this->load();

            // add() never replaces the list a publish has just put in the cache.
            Cache::add(self::CACHE_KEY, $cards, self::CACHE_SECONDS);
        }

        /** @var list<Card> $cards */
        return $this->published = array_map(PriceList::fromArray(...), $cards);
    }

    /**
     * Put the published cards in the cache again, after one is published or
     * withdrawn. Writing them, rather than only forgetting the key, means a
     * request that read the table just before cannot leave the old list cached.
     *
     * One refresh runs at a time, and each reads the table once it holds the
     * lock, after its own change committed. So the last list written has
     * every change made before it, even when two publishes overlap.
     */
    public function refresh(): void
    {
        $put = fn () => Cache::put(self::CACHE_KEY, $this->load(), self::CACHE_SECONDS);

        try {
            Cache::lock(self::CACHE_KEY.':refresh', 10)->block(5, $put);
        } catch (LockTimeoutException) {
            // The change has committed either way; write the list rather than fail.
            $put();
        }

        $this->published = null;
    }

    /**
     * Read every published card from the database, oldest first. Two cards
     * taking effect at the same moment are in the order they were created,
     * so the later one wins.
     *
     * @return list<Card>
     */
    private function load(): array
    {
        $cards = RateCard::query()
            ->published()
            ->with(['zones', 'routes.bands'])
            ->orderBy('effective_from')
            ->orderBy('id')
            ->get();

        return array_values(array_map(fn (RateCard $card): array => PriceList::fromModel($card)->toArray(), $cards->all()));
    }
}
