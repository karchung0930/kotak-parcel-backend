<?php

namespace App\Http\Requests\Admin;

use App\Enums\RateImportLayout;
use App\Models\RateImport;
use App\Support\RateSheets\Cells;
use App\Support\RateSheets\SheetReader;
use App\Support\RateSheets\ZoneMatcher;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * How to read an uploaded sheet, as the admin confirmed it: the layout,
 * the row with the headings and the units, then for a long layout the
 * column of each value, and for a matrix the weight column, the route each
 * other column holds (zones by the base card's codes) and the row with the
 * prices per extra kg. Columns count from 0 (A) and rows from 1, as in the
 * sheet.
 *
 * @phpstan-import-type Mapping from RateImport
 */
class UpdateRateImportMappingRequest extends FormRequest
{
    /**
     * Determine if the user may work on this import.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->import());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $import = $this->import();
        $column = ['integer', 'between:0,'.max(0, min(SheetReader::MAX_COLUMNS, $import->preview['columns'] ?? 0) - 1)];
        $row = ['integer', 'between:1,'.max(1, $import->preview['last_row'] ?? 1)];
        $codes = array_column($import->baseZones() ?? [], 'code');
        $long = 'exclude_unless:layout,'.RateImportLayout::Long->value;
        $matrix = 'exclude_unless:layout,'.RateImportLayout::Matrix->value;

        return [
            'layout' => ['required', Rule::enum(RateImportLayout::class)],
            'header_row' => ['required', ...$row],
            'weight_unit' => ['required', Rule::in(['kg', 'g'])],
            'price_unit' => ['required', Rule::in(['rm', 'sen'])],
            'columns' => [$long, 'required', 'array:origin,destination,weight,price'],
            'columns.origin' => [$long, 'required', ...$column],
            'columns.destination' => [$long, 'required', ...$column],
            'columns.weight' => [$long, 'required', ...$column],
            'columns.price' => [$long, 'required', ...$column],
            'weight_column' => [$matrix, 'required', ...$column],
            'extra_row' => [$matrix, 'nullable', ...$row],
            'routes' => [$matrix, 'required', 'list', 'max:'.SheetReader::MAX_COLUMNS],
            'routes.*' => [$matrix, 'array:column,origin,destination'],
            'routes.*.column' => [$matrix, 'required', ...$column],
            'routes.*.origin' => [$matrix, 'nullable', 'string', Rule::in($codes), 'required_with:routes.*.destination'],
            'routes.*.destination' => [$matrix, 'nullable', 'string', Rule::in($codes), 'required_with:routes.*.origin'],
        ];
    }

    /**
     * Get the checks across fields, once every value is valid: a different
     * column for each value, and for a matrix at least one route, each
     * route in one column only, and the extra-kg row under the headings.
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

                $mapping = $this->mapping();

                if (isset($mapping['columns'])) {
                    $seen = [];

                    foreach ($mapping['columns'] as $role => $column) {
                        if (isset($seen[$column])) {
                            $validator->errors()->add("columns.{$role}", 'Choose a column not used for anything else.');
                        }

                        $seen[$column] = true;
                    }

                    return;
                }

                $zones = new ZoneMatcher($this->import()->baseZones() ?? []);
                $routes = [];

                foreach ($mapping['routes'] ?? [] as $i => $route) {
                    if ($route['column'] === ($mapping['weight_column'] ?? null) && $route['origin'] !== null) {
                        $validator->errors()->add("routes.{$i}.origin", 'This is the weight column.');
                    }

                    if ($route['origin'] === null || $route['destination'] === null) {
                        continue;
                    }

                    $pair = "{$route['origin']}>{$route['destination']}";

                    if (isset($routes[$pair])) {
                        $validator->errors()->add("routes.{$i}.origin", sprintf(
                            'Column %s is already %s.',
                            Cells::column($routes[$pair]),
                            $zones->routeName($route['origin'], $route['destination']),
                        ));
                    }

                    $routes[$pair] ??= $route['column'];
                }

                if ($routes === []) {
                    $validator->errors()->add('routes', 'Choose the route of at least one column.');
                }

                if (($mapping['extra_row'] ?? null) !== null && $mapping['extra_row'] <= $mapping['header_row']) {
                    $validator->errors()->add('extra_row', 'Choose a row under the headings.');
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
        return [
            'layout.*' => 'Choose how the sheet is laid out.',
            'header_row.*' => 'Choose a row of the sheet.',
            'weight_unit.*' => 'Choose kg or grams.',
            'price_unit.*' => 'Choose ringgit or sen.',
            'columns.*.required' => 'Choose a column.',
            'columns.*.*' => 'Choose a column of the sheet.',
            'weight_column.*' => 'Choose the column with the weights.',
            'extra_row.*' => 'Choose a row of the sheet, or leave it empty.',
            'routes.required' => 'Choose the route of at least one column.',
            'routes.*.origin.*' => 'Choose a route from the list.',
            'routes.*.destination.*' => 'Choose a route from the list.',
            'routes.*.*' => 'Choose a column of the sheet.',
        ];
    }

    /**
     * Get the validated layout.
     */
    public function layout(): RateImportLayout
    {
        return RateImportLayout::from((string) $this->validated('layout'));
    }

    /**
     * Get the validated mapping, as App\Support\RateSheets\RateSheetParser reads it.
     *
     * @return Mapping
     */
    public function mapping(): array
    {
        $data = $this->validated();
        $mapping = [
            'header_row' => (int) $data['header_row'],
            'weight_unit' => $data['weight_unit'] === 'g' ? 'g' : 'kg',
            'price_unit' => $data['price_unit'] === 'sen' ? 'sen' : 'rm',
        ];

        if ($data['layout'] === RateImportLayout::Long->value) {
            return $mapping + ['columns' => [
                'origin' => (int) $data['columns']['origin'],
                'destination' => (int) $data['columns']['destination'],
                'weight' => (int) $data['columns']['weight'],
                'price' => (int) $data['columns']['price'],
            ]];
        }

        return $mapping + [
            'weight_column' => (int) $data['weight_column'],
            'extra_row' => isset($data['extra_row']) ? (int) $data['extra_row'] : null,
            'routes' => array_values(array_map(fn (array $route): array => [
                'column' => (int) $route['column'],
                'origin' => isset($route['origin']) ? (string) $route['origin'] : null,
                'destination' => isset($route['destination']) ? (string) $route['destination'] : null,
            ], $data['routes'])),
        ];
    }

    /**
     * Get the import from the route.
     */
    public function import(): RateImport
    {
        $import = $this->route('rateImport');

        return $import instanceof RateImport ? $import : abort(404);
    }
}
