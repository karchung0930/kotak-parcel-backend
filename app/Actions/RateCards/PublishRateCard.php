<?php

namespace App\Actions\RateCards;

use App\Enums\MalaysianState;
use App\Enums\RateCardStatus;
use App\Models\RateCard;
use App\Models\RateCardRoute;
use App\Models\RateCardZone;
use App\Models\User;
use App\Support\RateCards;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PublishRateCard
{
    /**
     * How far ahead rates can be scheduled, in years.
     */
    public const MAX_YEARS_AHEAD = 2;

    /**
     * Create a new action instance.
     */
    public function __construct(
        private RateCards $rateCards,
    ) {}

    /**
     * Publish a complete draft, in effect now or from a future moment. From
     * then on the card never changes. Orders pick it up when it takes
     * effect, without any further step (see RateCards::current()).
     *
     * Two published cards never take effect at the same moment, so the
     * card announced as upcoming is always the one that takes over.
     *
     * @param  CarbonInterface|null  $effectiveFrom  null for straight away
     *
     * @throws ValidationException listing every problem with the draft, or a moment that cannot be used
     */
    public function handle(RateCard $card, User $admin, ?CarbonInterface $effectiveFrom = null): RateCard
    {
        $card = DB::transaction(function () use ($card, $admin, $effectiveFrom): RateCard {
            $card = $card->freshLocked();

            if (! $card->isDraft()) {
                throw ValidationException::withMessages(['card' => 'These rates are already published.']);
            }

            $timeProblem = $effectiveFrom === null ? null : self::timeProblem($effectiveFrom);

            if ($timeProblem !== null) {
                throw ValidationException::withMessages(['effective_at' => $timeProblem]);
            }

            $problems = $this->problems($card);

            if ($problems !== []) {
                // One key each, so every problem reaches the page.
                throw ValidationException::withMessages(
                    collect($problems)->mapWithKeys(fn (string $problem, int $i): array => ["problems.{$i}" => $problem])->all(),
                );
            }

            // A time earlier in this minute means straight away.
            $from = ($effectiveFrom === null || $effectiveFrom->isPast() ? now() : $effectiveFrom)->toImmutable()->utc();

            if (RateCard::query()->published()->whereKeyNot($card->id)->where('effective_from', $from)->exists()) {
                $field = $effectiveFrom === null ? 'card' : 'effective_at';

                throw ValidationException::withMessages([
                    $field => 'Other rates take effect at that moment. Withdraw them first or choose another time.',
                ]);
            }

            $card->forceFill([
                'status' => RateCardStatus::Published,
                'effective_from' => $from,
                'published_by' => $admin->id,
                'published_at' => now(),
            ])->save();

            return $card;
        });

        $this->rateCards->refresh();

        return $card;
    }

    /**
     * Get what is wrong with a moment for rates to take effect, or null when
     * it will do: from the start of this minute (times are picked to the
     * minute), up to two years ahead, which also keeps it within the range
     * of the timestamp column.
     */
    public static function timeProblem(CarbonInterface $effectiveFrom): ?string
    {
        if ($effectiveFrom->lessThan(now()->startOfMinute())) {
            return 'Choose a time from now on.';
        }

        if ($effectiveFrom->greaterThan(now()->addYears(self::MAX_YEARS_AHEAD))) {
            return 'Choose a date within the next two years.';
        }

        return null;
    }

    /**
     * List everything that stops the card from being published, in plain
     * words: every state in exactly one zone and no zone left empty, a route
     * for every ordered pair of zones, and on each route at least one band
     * within the weight limit, prices that never go down as the weight goes
     * up and a price per extra kg.
     *
     * @return list<string>
     */
    public function problems(RateCard $card): array
    {
        $card->loadMissing(['zones', 'routes.bands']);
        $problems = [];

        if ($card->volumetric_divisor < 1) {
            $problems[] = 'Set a volumetric divisor above 0.';
        }

        if ($card->zones->isEmpty()) {
            return [...$problems, 'Add at least one zone.'];
        }

        $problems = [...$problems, ...$this->stateProblems($card)];

        $routes = $card->routes->keyBy(fn (RateCardRoute $route): string => "{$route->origin_zone_id}-{$route->destination_zone_id}");

        foreach ($card->zones as $from) {
            foreach ($card->zones as $to) {
                $route = $routes->get("{$from->id}-{$to->id}");
                $label = self::routeLabel($from, $to);

                if ($route === null) {
                    $problems[] = "{$label}: add the prices.";

                    continue;
                }

                foreach ($this->routeProblems($route) as $problem) {
                    $problems[] = "{$label}: {$problem}";
                }
            }
        }

        return $problems;
    }

    /**
     * Get the problems with which states are in which zone: every state in
     * exactly one zone, and no zone without states.
     *
     * @return list<string>
     */
    private function stateProblems(RateCard $card): array
    {
        $zonesByState = [];
        $empty = [];

        foreach ($card->zones as $zone) {
            if ($zone->states === []) {
                $empty[] = sprintf('%s has no states. Add states or remove the zone.', $zone->name);
            }

            foreach ($zone->states as $state) {
                $zonesByState[$state][] = $zone->name;
            }
        }

        $problems = [];
        $missing = [];

        foreach (MalaysianState::cases() as $state) {
            $zones = $zonesByState[$state->value] ?? [];

            if ($zones === []) {
                $missing[] = $state->label();
            } elseif (count($zones) > 1) {
                $problems[] = sprintf('%s is in more than one zone (%s).', $state->label(), implode(', ', $zones));
            }
        }

        if (count($missing) === 1) {
            array_unshift($problems, "{$missing[0]} is not in any zone.");
        } elseif ($missing !== []) {
            array_unshift($problems, 'These states are not in any zone: '.implode(', ', $missing).'.');
        }

        return [...$problems, ...$empty];
    }

    /**
     * Get the problems with one route's bands and its price per extra kg.
     *
     * @return list<string>
     */
    private function routeProblems(RateCardRoute $route): array
    {
        $bands = $route->bands->sortBy('max_weight_g')->values();

        if ($bands->isEmpty()) {
            return ['add at least one weight band.'];
        }

        $limit = config()->integer('kotak.max_weight_g');
        $problems = [];
        $previous = null;

        foreach ($bands as $band) {
            if ($band->max_weight_g < 1 || $band->max_weight_g > $limit) {
                $problems[] = sprintf('the %s band is over the %s limit.', self::kg($band->max_weight_g), self::kg($limit));
            }

            if ($previous !== null && $band->price_sen < $previous->price_sen) {
                $problems[] = sprintf(
                    'up to %s costs less than up to %s. Prices must not go down as the weight goes up.',
                    self::kg($band->max_weight_g),
                    self::kg($previous->max_weight_g),
                );
            }

            $previous = $band;
        }

        if ($route->extra_kg_sen === null) {
            $problems[] = 'set the price per extra kg.';
        }

        return $problems;
    }

    /**
     * Name a route for messages: "Within Sarawak" or "Peninsular Malaysia → Sarawak".
     */
    private static function routeLabel(RateCardZone $from, RateCardZone $to): string
    {
        return $from->is($to) ? "Within {$from->name}" : "{$from->name} → {$to->name}";
    }

    /**
     * Write a weight in kg for messages: 500 → "0.5 kg", 30000 → "30 kg".
     */
    private static function kg(int $grams): string
    {
        return rtrim(rtrim(number_format($grams / 1000, 3, '.', ''), '0'), '.').' kg';
    }
}
