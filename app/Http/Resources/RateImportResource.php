<?php

namespace App\Http\Resources;

use App\Models\RateCard;
use App\Models\RateImport;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A rate card import with everything read from its file: the preview of
 * its first rows (only while the columns or the sheet can still be
 * changed, as nothing else shows it), the mapping, the problems found or,
 * once checked, every route's bands in grams and sen. The uploader, the
 * base card and the draft made from it are included when loaded.
 *
 * @mixin RateImport
 */
class RateImportResource extends JsonResource
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
            'format' => $this->format(),
            'sheet' => $this->sheet,
            'status' => $this->status->toOption(),
            'is_running' => $this->status->isRunning(),
            'layout' => $this->layout?->toOption(),
            'mapping' => $this->mapping,
            'errors' => $this->errors,
            'preview' => $this->when($this->path !== null && ($this->canBeMapped() || $this->canChooseSheet()), $this->preview),
            'summary' => $this->summary,
            'file_kept' => $this->path !== null,
            'draft_deleted' => $this->draftWasDeleted(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
            'user' => $this->whenLoaded('user', fn (User $user): array => ['id' => $user->id, 'name' => $user->name]),
            'base_rate_card' => $this->whenLoaded('baseRateCard', fn (RateCard $card): array => ['id' => $card->id, 'name' => $card->name]),
            'rate_card' => $this->whenLoaded('rateCard', fn (RateCard $card): array => ['id' => $card->id, 'name' => $card->name]),
        ];
    }
}
