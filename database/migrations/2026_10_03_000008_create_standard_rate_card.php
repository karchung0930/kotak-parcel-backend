<?php

use App\Enums\MalaysianState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The prices config/kotak.php held before rate cards: RM 8.00 for the
     * first kg, then RM 2.00 for each further started kg, on the greater of
     * the actual weight and length x width x height (cm) / 5000.
     */
    private const FIRST_KG_SEN = 800;

    private const PER_KG_SEN = 200;

    private const VOLUMETRIC_DIVISOR = 5000;

    /**
     * Run the migrations.
     *
     * Publishes those prices as the first rate card, so every installation
     * starts with a current card and no seeder is needed: one zone with every
     * state, one route within it, and one band up to 1 kg plus a price per
     * extra kg, which gives exactly the old formula. Every existing order was
     * priced with it, so it takes effect before the earliest order.
     */
    public function up(): void
    {
        $now = CarbonImmutable::now();
        $effectiveFrom = $now->subMinute();
        $earliestOrder = DB::table('orders')->min('created_at');

        if (is_string($earliestOrder)) {
            $effectiveFrom = $effectiveFrom->min(CarbonImmutable::parse($earliestOrder, 'UTC'));
        }

        DB::transaction(function () use ($now, $effectiveFrom): void {
            $cardId = DB::table('rate_cards')->insertGetId([
                'name' => 'Standard rates',
                'status' => 'published',
                'effective_from' => $effectiveFrom,
                'volumetric_divisor' => self::VOLUMETRIC_DIVISOR,
                'notes' => 'One price for the whole of Malaysia.',
                'published_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $zoneId = DB::table('rate_card_zones')->insertGetId([
                'rate_card_id' => $cardId,
                'code' => 'malaysia',
                'name' => 'Malaysia',
                'states' => json_encode(array_column(MalaysianState::cases(), 'value'), JSON_THROW_ON_ERROR),
            ]);

            $routeId = DB::table('rate_card_routes')->insertGetId([
                'rate_card_id' => $cardId,
                'origin_zone_id' => $zoneId,
                'destination_zone_id' => $zoneId,
                'extra_kg_sen' => self::PER_KG_SEN,
            ]);

            DB::table('rate_card_bands')->insert([
                'rate_card_route_id' => $routeId,
                'max_weight_g' => 1000,
                'price_sen' => self::FIRST_KG_SEN,
            ]);

            DB::table('orders')->update(['estimated_rate_card_id' => $cardId]);
            DB::table('orders')->whereNotNull('final_price_sen')->update(['final_rate_card_id' => $cardId]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $cardId = DB::table('rate_cards')->orderBy('id')->value('id');

        if ($cardId === null) {
            return;
        }

        DB::transaction(function () use ($cardId): void {
            DB::table('orders')->where('estimated_rate_card_id', $cardId)->update(['estimated_rate_card_id' => null]);
            DB::table('orders')->where('final_rate_card_id', $cardId)->update(['final_rate_card_id' => null]);
            DB::table('rate_cards')->where('id', $cardId)->delete();
        });
    }
};
