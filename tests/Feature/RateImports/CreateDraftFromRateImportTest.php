<?php

namespace Tests\Feature\RateImports;

use App\Actions\RateImports\CreateDraftFromRateImport;
use App\Enums\RateImportStatus;
use App\Models\RateCard;
use App\Models\RateCardBand;
use App\Models\RateCardRoute;
use App\Models\RateCardZone;
use App\Models\RateImport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Tests\Concerns\CreatesRateCards;
use Tests\TestCase;

/**
 * Making the draft from a checked import: the one write to the rate card
 * tables, in one transaction with marking the import applied.
 */
class CreateDraftFromRateImportTest extends TestCase
{
    use CreatesRateCards;
    use RefreshDatabase;

    private User $admin;

    private RateCard $zones;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->zones = $this->zoneRates();
        $this->zones->forceFill(['volumetric_divisor' => 6000])->save();
    }

    public function test_the_draft_has_the_base_card_s_zones_and_divisor_and_the_file_s_prices()
    {
        $routes = self::routes();
        $routes[0]['bands'] = [['maxWeightG' => 500, 'priceSen' => 650], ['maxWeightG' => 1000, 'priceSen' => 850]];
        $import = $this->readyImport($routes);

        $draft = app(CreateDraftFromRateImport::class)->handle($import, $this->admin);

        $this->assertTrue($draft->isDraft());
        $this->assertSame(6000, $draft->volumetric_divisor);
        $this->assertSame(
            $this->zones->zones->map->only(['code', 'name', 'states'])->all(),
            $draft->zones->map->only(['code', 'name', 'states'])->all(),
        );
        $this->assertNotEquals($this->zones->zones->pluck('id')->all(), $draft->zones->pluck('id')->all());

        $within = $draft->routes()->with('bands')->firstOrFail();
        $this->assertSame([$draft->zones[0]->id, $draft->zones[0]->id], [$within->origin_zone_id, $within->destination_zone_id]);
        $this->assertSame([[500, 650], [1000, 850]], $within->bands->map(fn (RateCardBand $band) => [$band->max_weight_g, $band->price_sen])->all());
        $this->assertSame(9, $draft->routes()->count());

        $import->refresh();
        $this->assertSame(RateImportStatus::Applied, $import->status);
        $this->assertSame($draft->id, $import->rate_card_id);
    }

    public function test_a_failure_halfway_leaves_no_draft_and_the_import_ready()
    {
        $routes = self::routes();
        // A zone the base card lacks, on the last route: the draft is half made when it fails.
        $routes[8]['to'] = 'singapore';
        $import = $this->readyImport($routes);
        $counts = fn (): array => [RateCard::query()->count(), RateCardZone::query()->count(), RateCardRoute::query()->count(), RateCardBand::query()->count()];
        $before = $counts();

        try {
            app(CreateDraftFromRateImport::class)->handle($import, $this->admin);
            $this->fail('The draft should not have been made.');
        } catch (InvalidArgumentException) {
            // Rolled back.
        }

        $this->assertSame($before, $counts());
        $this->assertSame(RateImportStatus::Ready, $import->refresh()->status);
        $this->assertNull($import->rate_card_id);
    }

    public function test_only_a_ready_import_becomes_a_draft_and_only_once()
    {
        foreach ([RateImportStatus::Uploaded, RateImportStatus::NeedsMapping, RateImportStatus::Validating, RateImportStatus::Failed] as $status) {
            $import = $this->readyImport(self::routes());
            $import->forceFill(['status' => $status])->save();

            $this->assertRefused('Only a file checked without problems can become a draft.', $import);
        }

        $import = $this->readyImport(self::routes());
        app(CreateDraftFromRateImport::class)->handle($import, $this->admin);

        $this->assertRefused('A draft was already made from this file.', $import);
        $this->assertSame(1, RateCard::query()->where('name', 'Imported from rates')->count());
    }

    public function test_the_draft_is_named_after_the_file_in_words_and_the_notes_keep_the_exact_name()
    {
        $import = $this->readyImport(self::routes());
        $import->forceFill(['original_name' => 'Kotak_Rate_Card_2027__East-Malaysia_v2.xlsx'])->save();

        $draft = app(CreateDraftFromRateImport::class)->handle($import, $this->admin);

        $this->assertSame('Imported from Kotak Rate Card 2027 East-Malaysia v2', $draft->name);
        $this->assertStringContainsString('Kotak_Rate_Card_2027__East-Malaysia_v2.xlsx', (string) $draft->notes);
    }

    public function test_a_base_card_whose_zones_changed_since_the_check_is_refused()
    {
        $base = RateCard::factory()->withRates(self::zones(), [])->create(['name' => 'Draft zones']);
        $import = $this->readyImport(self::routes(), $base);

        $base->zones()->where('code', 'sarawak')->delete();

        $this->assertRefused('The zones of “Draft zones” changed after the file was checked. Check the file again.', $import);

        $base->delete();

        $this->assertRefused('The rates whose zones this file uses were deleted. Upload the file again and choose other rates.', $import->refresh());
    }

    /**
     * An import checked without problems, holding the given routes.
     *
     * @param  list<array<string, mixed>>  $routes
     */
    private function readyImport(array $routes, ?RateCard $base = null): RateImport
    {
        return RateImport::factory()->status(RateImportStatus::Ready)->create([
            'user_id' => $this->admin->id,
            'base_rate_card_id' => ($base ?? $this->zones)->id,
            'sheet' => 'Rates',
            'summary' => ['rows' => 34, 'bands' => 25, 'routes' => $routes],
        ]);
    }

    private function assertRefused(string $message, RateImport $import): void
    {
        try {
            app(CreateDraftFromRateImport::class)->handle($import, $this->admin);
            $this->fail("Expected a refusal: {$message}");
        } catch (ValidationException $e) {
            $this->assertSame(['import' => [$message]], $e->errors());
        }
    }
}
