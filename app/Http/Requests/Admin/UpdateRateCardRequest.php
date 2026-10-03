<?php

namespace App\Http\Requests\Admin;

use App\Enums\MalaysianState;
use App\Models\RateCard;
use App\Models\RateCardZone;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A draft rate card as the editor sends it: weights in grams and prices in
 * sen, each route naming its zones by their position in the zones list.
 *
 * Each value must be valid, and a state can only be in one zone. A draft
 * may still be incomplete (states or routes missing, prices that go down),
 * which publishing reports in full.
 */
class UpdateRateCardRequest extends FormRequest
{
    /**
     * The most a price can be, in sen (RM 10,000.00).
     */
    private const MAX_PRICE_SEN = 1_000_000;

    /**
     * The volumetric divisors allowed. Couriers use 4000 to 6000; the
     * bounds also keep the price of the largest parcel within the order
     * columns, whatever a typo in the divisor.
     */
    private const MIN_DIVISOR = 1000;

    private const MAX_DIVISOR = 10000;

    /**
     * Determine if the user may edit rate cards. Whether this one is still
     * a draft is for UpdateRateCardDraft to say, so an admin who saves
     * after it was published is told why, rather than refused outright.
     */
    public function authorize(): bool
    {
        return $this->user()->can('manage', RateCard::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $zones = $this->input('zones');
        $zoneIndexes = is_array($zones) ? array_keys($zones) : [];
        $price = ['integer', 'between:0,'.self::MAX_PRICE_SEN];
        $states = count(MalaysianState::cases());

        return [
            'name' => ['required', 'string', 'max:100'],
            'volumetric_divisor' => ['required', 'integer', 'between:'.self::MIN_DIVISOR.','.self::MAX_DIVISOR],
            'notes' => ['nullable', 'string', 'max:2000'],
            'zones' => ['present', 'list', 'max:'.$states],
            'zones.*' => ['array:name,states'],
            'zones.*.name' => ['required', 'string', 'max:60', function (string $attribute, mixed $value, Closure $fail): void {
                // The zone's code is made from its name (RateCardZone::codeFor()).
                if (is_string($value) && RateCardZone::codeFor($value) === '') {
                    $fail('Use Latin letters or numbers in the name.');
                }
            }],
            'zones.*.states' => ['present', 'list', 'max:'.$states],
            'zones.*.states.*' => ['string', Rule::enum(MalaysianState::class)],
            // At most one route for each ordered pair of zones.
            'routes' => ['present', 'list', 'max:'.($states ** 2)],
            'routes.*' => ['array:origin,destination,extra_kg_sen,bands'],
            'routes.*.origin' => ['required', 'integer', Rule::in($zoneIndexes)],
            'routes.*.destination' => ['required', 'integer', Rule::in($zoneIndexes)],
            'routes.*.extra_kg_sen' => ['nullable', ...$price],
            'routes.*.bands' => ['present', 'list', 'max:30'],
            'routes.*.bands.*' => ['array:max_weight_g,price_sen'],
            'routes.*.bands.*.max_weight_g' => ['required', 'integer', 'between:1,'.config()->integer('kotak.max_weight_g')],
            'routes.*.bands.*.price_sen' => ['required', ...$price],
        ];
    }

    /**
     * Get the checks across fields, once every value is valid: zone names
     * that make different codes, each state in one zone, each route and
     * band weight once. A state listed twice in one zone is kept once.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                /** @var array{zones: list<array{name: string, states: list<string>}>, routes: list<array{origin: int, destination: int, bands: list<array{max_weight_g: int}>}>} $data */
                $data = $validator->validated();
                $codes = [];
                $zoneOf = [];

                foreach ($data['zones'] as $i => $zone) {
                    $code = RateCardZone::codeFor($zone['name']);

                    if (isset($codes[$code])) {
                        $validator->errors()->add("zones.{$i}.name", 'Another zone already has this name.');
                    }

                    $codes[$code] = true;

                    foreach ($zone['states'] as $state) {
                        if (isset($zoneOf[$state]) && $zoneOf[$state] !== $i) {
                            $validator->errors()->add("zones.{$i}.states", sprintf(
                                '%s is already in %s. A state can only be in one zone.',
                                MalaysianState::from($state)->label(),
                                $data['zones'][$zoneOf[$state]]['name'],
                            ));
                        }

                        $zoneOf[$state] ??= $i;
                    }
                }

                $routes = [];

                foreach ($data['routes'] as $i => $route) {
                    $pair = "{$route['origin']}-{$route['destination']}";

                    if (isset($routes[$pair])) {
                        $validator->errors()->add("routes.{$i}", 'This route is listed twice.');
                    }

                    $routes[$pair] = true;
                    $weights = [];

                    foreach ($route['bands'] as $j => $band) {
                        if (isset($weights[$band['max_weight_g']])) {
                            $validator->errors()->add("routes.{$i}.bands.{$j}.max_weight_g", 'This weight is already a band.');
                        }

                        $weights[$band['max_weight_g']] = true;
                    }
                }
            },
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $maxKg = config()->integer('kotak.max_weight_g') / 1000;

        return [
            'name.required' => 'Name this version, e.g. "Rates from 1 January".',
            'name.max' => 'Use at most 100 characters.',
            'volumetric_divisor.*' => 'Enter a whole number from '.self::MIN_DIVISOR.' to '.self::MAX_DIVISOR.'.',
            'notes.max' => 'Keep the notes under 2000 characters.',
            'zones.max' => 'Use at most one zone per state.',
            'zones.*.name.required' => 'Name the zone.',
            'zones.*.name.max' => 'Use at most 60 characters.',
            'zones.*.states.max' => 'Choose each state once.',
            'zones.*.states.*' => 'Choose states from the list.',
            'routes.max' => 'List each route once.',
            'routes.*.origin.*' => 'Choose a zone from the list.',
            'routes.*.destination.*' => 'Choose a zone from the list.',
            'routes.*.extra_kg_sen.*' => 'Enter a price from RM 0.00 to RM 10,000.00.',
            'routes.*.bands.max' => 'Use at most 30 weight bands.',
            'routes.*.bands.*.max_weight_g.*' => "Enter a weight above 0 and up to {$maxKg} kg.",
            'routes.*.bands.*.price_sen.*' => 'Enter a price from RM 0.00 to RM 10,000.00.',
        ];
    }

    /**
     * Get the validated draft, as UpdateRateCardDraft takes it.
     *
     * @return array{
     *     name: string,
     *     volumetric_divisor: int,
     *     notes?: string|null,
     *     zones: list<array{name: string, states: list<string>}>,
     *     routes: list<array{origin: int, destination: int, extra_kg_sen?: int|null, bands: list<array{max_weight_g: int, price_sen: int}>}>,
     * }
     */
    public function draft(): array
    {
        $data = $this->validated();

        return [
            'name' => (string) $data['name'],
            'volumetric_divisor' => (int) $data['volumetric_divisor'],
            'notes' => isset($data['notes']) ? (string) $data['notes'] : null,
            'zones' => array_values(array_map(fn (array $zone): array => [
                'name' => (string) $zone['name'],
                'states' => array_values(array_unique(array_map(strval(...), $zone['states']))),
            ], $data['zones'])),
            'routes' => array_values(array_map(fn (array $route): array => [
                'origin' => (int) $route['origin'],
                'destination' => (int) $route['destination'],
                'extra_kg_sen' => isset($route['extra_kg_sen']) ? (int) $route['extra_kg_sen'] : null,
                'bands' => array_values(array_map(fn (array $band): array => [
                    'max_weight_g' => (int) $band['max_weight_g'],
                    'price_sen' => (int) $band['price_sen'],
                ], $route['bands'])),
            ], $data['routes'])),
        ];
    }
}
