<?php

namespace App\Models;

use App\Enums\RateImportLayout;
use App\Enums\RateImportStatus;
use App\Support\PriceList;
use Carbon\CarbonInterface;
use Database\Factories\RateImportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A spreadsheet of prices uploaded by an admin, on its way to a draft rate
 * card. The file stays on the private disk for a week; what was read from
 * it is kept here:
 *
 * - preview: the sheet names, the first rows as text and the sheet's size
 *   (cut_short when it goes on beyond the rows read);
 * - mapping: which column (or row) holds what, and in which units;
 * - errors: the problems found, the first 200 with the total;
 * - summary: once checked, every route with its bands in grams and sen,
 *   zones named by the base card's codes (App\Support\PriceList's form).
 *
 * @phpstan-import-type Route from PriceList
 * @phpstan-import-type Zone from PriceList
 *
 * @phpstan-type Mapping array{
 *     header_row: int,
 *     weight_unit: 'kg'|'g',
 *     price_unit: 'rm'|'sen',
 *     columns?: array{origin: int|null, destination: int|null, weight: int|null, price: int|null},
 *     weight_column?: int,
 *     routes?: list<array{column: int, origin: string|null, destination: string|null}>,
 *     extra_row?: int|null,
 * }
 * @phpstan-type Preview array{sheets: list<string>, rows: list<array{number: int, cells: list<string>}>, columns: int, last_row: int, cut_short?: bool}
 * @phpstan-type Problem array{row: int|null, column: string|null, message: string}
 * @phpstan-type Problems array{total: int, items: list<Problem>}
 * @phpstan-type Summary array{rows: int, bands: int, routes: list<Route>}
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $base_rate_card_id
 * @property string $original_name
 * @property string|null $path
 * @property string|null $sheet
 * @property RateImportStatus $status
 * @property RateImportLayout|null $layout
 * @property Mapping|null $mapping
 * @property Problems|null $errors
 * @property Preview|null $preview
 * @property Summary|null $summary
 * @property int|null $rate_card_id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class RateImport extends Model
{
    /** @use HasFactory<RateImportFactory> */
    use HasFactory;

    /**
     * How long uploaded files are kept, in days.
     */
    public const KEEP_FILES_DAYS = 7;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'uploaded',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RateImportStatus::class,
            'layout' => RateImportLayout::class,
            'mapping' => 'array',
            'errors' => 'array',
            'preview' => 'array',
            'summary' => 'array',
        ];
    }

    /**
     * The admin who uploaded the file.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The card whose zones and divisor the imported prices use.
     *
     * @return BelongsTo<RateCard, $this>
     */
    public function baseRateCard(): BelongsTo
    {
        return $this->belongsTo(RateCard::class, 'base_rate_card_id');
    }

    /**
     * The draft made from the file.
     *
     * @return BelongsTo<RateCard, $this>
     */
    public function rateCard(): BelongsTo
    {
        return $this->belongsTo(RateCard::class);
    }

    /**
     * Get the file's format from its stored name: xlsx or csv.
     *
     * @return 'xlsx'|'csv'
     */
    public function format(): string
    {
        return strtolower(pathinfo((string) ($this->path ?? $this->original_name), PATHINFO_EXTENSION)) === 'csv' ? 'csv' : 'xlsx';
    }

    /**
     * Get the uploaded file's full path, or null once it has been deleted.
     */
    public function localPath(): ?string
    {
        $disk = Storage::disk('local');

        return $this->path !== null && $disk->exists($this->path) ? $disk->path($this->path) : null;
    }

    /**
     * Get the base card's zones in App\Support\PriceList's form, in the
     * card's order, or null when the card is gone (a deleted draft) or has
     * no zones.
     *
     * @return list<Zone>|null
     */
    public function baseZones(): ?array
    {
        $zones = array_map(fn (RateCardZone $zone): array => [
            'code' => $zone->code,
            'name' => $zone->name,
            'states' => $zone->states,
        ], array_values($this->baseRateCard?->zones->all() ?? []));

        return $zones === [] ? null : $zones;
    }

    /**
     * Determine if the mapping can be set (again): once the file has been
     * read, until a draft is made from it. A file that could not be read has
     * no headings to map.
     */
    public function canBeMapped(): bool
    {
        return in_array($this->status, [RateImportStatus::NeedsMapping, RateImportStatus::Failed, RateImportStatus::Ready], true)
            && $this->layout !== null
            && $this->preview !== null;
    }

    /**
     * Determine if another sheet of the workbook can be read instead: once
     * its sheet names are known, until a draft is made from it.
     */
    public function canChooseSheet(): bool
    {
        return in_array($this->status, [RateImportStatus::NeedsMapping, RateImportStatus::Failed, RateImportStatus::Ready], true)
            && count($this->preview['sheets'] ?? []) > 1;
    }

    /**
     * Determine if a draft was made from the file and then deleted (which
     * clears rate_card_id), so it can be made again.
     */
    public function draftWasDeleted(): bool
    {
        return $this->status === RateImportStatus::Applied && $this->rate_card_id === null;
    }

    /**
     * Determine if a draft can be made from the file: it was checked
     * without problems, and no draft made from it is left.
     */
    public function canMakeDraft(): bool
    {
        return $this->summary !== null
            && ($this->status === RateImportStatus::Ready || $this->draftWasDeleted());
    }

    /**
     * Re-read the import and hold a row lock on it until the current transaction ends.
     */
    public function freshLocked(): static
    {
        return static::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();
    }
}
