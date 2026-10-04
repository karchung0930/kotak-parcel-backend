<?php

namespace App\Actions\RateImports;

use App\Actions\RateCards\CreateRateCardDraft;
use App\Enums\RateImportStatus;
use App\Models\RateCard;
use App\Models\RateImport;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateDraftFromRateImport
{
    /**
     * Create a new action instance.
     */
    public function __construct(
        private CreateRateCardDraft $createDraft,
    ) {}

    /**
     * Make a draft rate card from a checked file: the base card's zones and
     * divisor with the file's routes and bands. It is published like any
     * other draft. This is the first time anything is written to the rate
     * card tables, all in one transaction with marking the import applied,
     * so a failure leaves neither a half-made draft nor a used import.
     *
     * A draft made from the file and then deleted can be made again.
     *
     * @throws ValidationException when the file is not ready, its draft still exists, or the base card's zones changed
     */
    public function handle(RateImport $import, User $admin): RateCard
    {
        return DB::transaction(function () use ($import, $admin): RateCard {
            $import = $import->freshLocked();

            if ($import->status === RateImportStatus::Applied && $import->rateCard()->exists()) {
                throw ValidationException::withMessages(['import' => 'A draft was already made from this file.']);
            }

            if (! in_array($import->status, [RateImportStatus::Ready, RateImportStatus::Applied], true) || $import->summary === null) {
                throw ValidationException::withMessages(['import' => 'Only a file checked without problems can become a draft.']);
            }

            $base = $import->baseRateCard()->with('zones')->first();

            if ($base === null) {
                throw ValidationException::withMessages(['import' => 'The rates whose zones this file uses were deleted. Upload the file again and choose other rates.']);
            }

            // The file was checked against these zones; a draft base may have changed since.
            $codes = $base->zones->pluck('code')->sort()->values()->all();
            $used = collect($import->summary['routes'])->pluck('from')->unique()->sort()->values()->all();

            if ($codes !== $used) {
                throw ValidationException::withMessages(['import' => "The zones of “{$base->name}” changed after the file was checked. Check the file again."]);
            }

            $draft = $this->createDraft->withRoutes(
                $admin,
                $base,
                $import->summary['routes'],
                $this->draftName($import),
                sprintf(
                    'Imported from %s%s, with the zones and divisor of %s.',
                    $import->original_name,
                    $import->sheet === null ? '' : " (sheet {$import->sheet})",
                    $base->name,
                ),
            );

            $import->forceFill(['status' => RateImportStatus::Applied, 'rate_card_id' => $draft->id])->save();

            return $draft;
        });
    }

    /**
     * Name the draft after the file, without its extension and with
     * underscores as spaces, so the name reads and wraps like words. The
     * notes keep the exact file name.
     */
    private function draftName(RateImport $import): string
    {
        $name = trim((string) preg_replace('/[_\s]+/', ' ', pathinfo($import->original_name, PATHINFO_FILENAME)));

        return Str::limit('Imported from '.($name !== '' ? $name : $import->original_name), 100, '');
    }
}
