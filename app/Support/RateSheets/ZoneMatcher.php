<?php

namespace App\Support\RateSheets;

use App\Enums\MalaysianState;
use App\Support\PriceList;
use Illuminate\Support\Str;

/**
 * Matches what a spreadsheet calls a zone ("Peninsular", "sabah-labuan",
 * "Sarawak", "West") to a zone of the base rate card, and reads route
 * headings ("Peninsular → Sabah & Labuan", "West - East", "Within
 * Sarawak") as a pair of zones.
 *
 * A name matches, in this order: the zone's code or name; a state, which
 * means the zone it is in; a common name for one side of Malaysia ("West",
 * "Semenanjung", "East", "Borneo") when one zone covers it; a part of the
 * zone's name ("Peninsular" for "Peninsular Malaysia"); a close spelling
 * ("Sarawk"). Anything that fits more than one zone matches none.
 *
 * @phpstan-import-type Zone from PriceList
 */
final class ZoneMatcher
{
    /**
     * Names for Peninsular (West) Malaysia and for East Malaysia.
     */
    private const WEST = ['west', 'west-malaysia', 'westm', 'wm', 'semenanjung', 'semenanjung-malaysia', 'peninsula', 'malaya'];

    private const EAST = ['east', 'east-malaysia', 'em', 'borneo', 'malaysia-timur'];

    /**
     * How alike two codes must be, in per cent, to match by spelling.
     */
    private const SIMILARITY = 80;

    /**
     * Route headings split at the first of these that leaves a zone on each side.
     */
    private const SEPARATORS = ['→', '⟶', '➔', '->', '=>', '>', ' to ', ' – ', ' — ', ' - ', '/', '–', '—', '-'];

    /**
     * What each name matched, so every name is worked out once.
     *
     * @var array<string, string|null>
     */
    private array $matched = [];

    /**
     * Create a matcher for the given zones.
     *
     * @param  list<Zone>  $zones
     */
    public function __construct(
        private array $zones,
    ) {}

    /**
     * Get the code of the zone a name stands for, or null when it fits none
     * (or more than one).
     */
    public function zone(string|int|float|null $name): ?string
    {
        $text = Cells::unescape(Cells::text($name));

        if ($text === '') {
            return null;
        }

        return $this->matched[$text] ??= $this->match($text);
    }

    /**
     * Read a route heading as [origin code, destination code], or null when
     * it does not name two zones.
     *
     * @return array{string, string}|null
     */
    public function route(string|int|float|null $heading): ?array
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', Cells::unescape(Cells::text($heading))));

        if ($text === '') {
            return null;
        }

        if (preg_match('/^within\s+(.+)$/iu', $text, $within) === 1) {
            $zone = $this->zone($within[1]);

            return $zone === null ? null : [$zone, $zone];
        }

        // "From Peninsular to Sabah" reads as "Peninsular to Sabah".
        $text = (string) preg_replace('/^from\s+/iu', '', $text);

        foreach (self::SEPARATORS as $separator) {
            $offset = 0;

            while (($at = mb_stripos($text, $separator, $offset)) !== false) {
                $from = $this->zone(trim(mb_substr($text, 0, $at)));
                $to = $this->zone(trim(mb_substr($text, $at + mb_strlen($separator))));

                if ($from !== null && $to !== null) {
                    return [$from, $to];
                }

                $offset = $at + 1;
            }
        }

        return null;
    }

    /**
     * Get the zone's name for messages, by its code.
     */
    public function name(string $code): string
    {
        foreach ($this->zones as $zone) {
            if ($zone['code'] === $code) {
                return $zone['name'];
            }
        }

        return $code;
    }

    /**
     * Name a route for messages: "Within Sarawak" or "Peninsular Malaysia → Sarawak".
     */
    public function routeName(string $from, string $to): string
    {
        return $from === $to ? "Within {$this->name($from)}" : "{$this->name($from)} → {$this->name($to)}";
    }

    /**
     * Work out which zone a name stands for.
     */
    private function match(string $text): ?string
    {
        $slug = Str::slug($text);

        if ($slug === '') {
            return null;
        }

        foreach ($this->zones as $zone) {
            if ($zone['code'] === $slug || Str::slug($zone['name']) === $slug) {
                return $zone['code'];
            }
        }

        foreach (MalaysianState::cases() as $state) {
            if (in_array($slug, [Str::slug($state->value), Str::slug($state->label())], true)) {
                return $this->unique(fn (array $zone): bool => in_array($state->value, $zone['states'], true));
            }
        }

        if (in_array($slug, self::WEST, true)) {
            return $this->unique(fn (array $zone): bool => in_array(MalaysianState::Selangor->value, $zone['states'], true)
                && ! in_array(MalaysianState::Sabah->value, $zone['states'], true));
        }

        if (in_array($slug, self::EAST, true)) {
            return $this->unique(fn (array $zone): bool => in_array(MalaysianState::Sabah->value, $zone['states'], true)
                && in_array(MalaysianState::Sarawak->value, $zone['states'], true)
                && ! in_array(MalaysianState::Selangor->value, $zone['states'], true));
        }

        // A whole word or words of the zone's name: "peninsular" in "peninsular-malaysia".
        $part = $this->unique(fn (array $zone): bool => str_contains("-{$zone['code']}-", "-{$slug}-"));

        if ($part !== null) {
            return $part;
        }

        return $this->closest($slug);
    }

    /**
     * Get the code of the one zone that passes the test, or null when none
     * or several do.
     *
     * @param  callable(Zone): bool  $test
     */
    private function unique(callable $test): ?string
    {
        $codes = array_values(array_map(fn (array $zone): string => $zone['code'], array_filter($this->zones, $test)));

        return count($codes) === 1 ? $codes[0] : null;
    }

    /**
     * Get the zone spelt most like the name, if it is close enough and
     * clearly closer than any other.
     */
    private function closest(string $slug): ?string
    {
        $scores = [];

        foreach ($this->zones as $zone) {
            similar_text($slug, $zone['code'], $percent);
            $scores[$zone['code']] = $percent;
        }

        arsort($scores);
        $best = array_key_first($scores);
        $values = array_values($scores);

        if ($best === null || $values[0] < self::SIMILARITY || (isset($values[1]) && $values[0] - $values[1] < 5)) {
            return null;
        }

        return (string) $best;
    }
}
