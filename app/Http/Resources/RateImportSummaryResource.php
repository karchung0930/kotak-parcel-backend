<?php

namespace App\Http\Resources;

use App\Models\RateCard;
use App\Models\RateImport;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A rate card import as a list row: the file, where it stands, who
 * uploaded it, the base card and the draft made from it (when loaded).
 *
 * @mixin RateImport
 */
class RateImportSummaryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'original_name' => $this->original_name,
            'status' => $this->status->toOption(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'user' => $this->whenLoaded('user', fn (User $user): array => ['id' => $user->id, 'name' => $user->name]),
            'base_rate_card' => $this->whenLoaded('baseRateCard', fn (RateCard $card): array => ['id' => $card->id, 'name' => $card->name]),
            'rate_card' => $this->whenLoaded('rateCard', fn (RateCard $card): array => ['id' => $card->id, 'name' => $card->name]),
        ];
    }
}
