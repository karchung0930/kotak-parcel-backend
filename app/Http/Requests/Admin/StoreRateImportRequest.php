<?php

namespace App\Http\Requests\Admin;

use App\Models\RateCard;
use App\Models\RateImport;
use App\Support\RateCards;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use ZipArchive;

/**
 * An uploaded spreadsheet of prices: an .xlsx or .csv file of at most
 * 5 MB, and the version whose zones its prices use (the current rates when
 * none is chosen).
 */
class StoreRateImportRequest extends FormRequest
{
    /**
     * The largest file accepted, in KB.
     */
    public const MAX_KB = 5120;

    /**
     * The most an .xlsx file may hold once unpacked, in bytes, as its zip
     * archive declares it. A workbook of prices stays far below; a file
     * built to swell when read does not.
     */
    public const MAX_UNPACKED_BYTES = 50 * 1024 * 1024;

    /**
     * How many times its packed size a large part of an .xlsx file may
     * unpack to (from 1 MB unpacked; small parts vary too much to judge).
     */
    private const MAX_PACKING_RATIO = 100;

    private const RATIO_FROM_BYTES = 1024 * 1024;

    /**
     * The types a file's content may be detected as, by its extension. An
     * .xlsx file is a zip archive, which older detection reports as such;
     * its parts are then checked (workbookProblem()).
     */
    private const TYPES = [
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/x-zip-compressed'],
        'csv' => ['text/csv', 'text/plain', 'application/csv', 'text/x-csv', 'application/vnd.ms-excel', 'text/comma-separated-values'],
    ];

    private const NOT_A_WORKBOOK = 'This file is not an Excel workbook. Save it again as .xlsx and upload it.';

    /**
     * Determine if the user may import rates.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', RateImport::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'extensions:xlsx,csv', 'max:'.self::MAX_KB],
            'base_rate_card_id' => ['nullable', 'integer', Rule::exists('rate_cards', 'id')],
        ];
    }

    /**
     * Get the check that the file's content matches its extension, so a
     * renamed file of another kind is refused here rather than read, and
     * that a workbook is not built to swell when it is read.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $file = $this->file('file');

                if (! $validator->errors()->has('file') && $file instanceof UploadedFile) {
                    $extension = strtolower($file->getClientOriginalExtension());

                    if (! in_array($file->getMimeType(), self::TYPES[$extension] ?? [], true)) {
                        $validator->errors()->add('file', $extension === 'csv'
                            ? 'This file is not a CSV text file. Save it again as .csv and upload it.'
                            : self::NOT_A_WORKBOOK);
                    } elseif ($extension === 'xlsx' && ($problem = self::workbookProblem($file->getPathname())) !== null) {
                        $validator->errors()->add('file', $problem);
                    }
                }

                if (! $validator->errors()->has('base_rate_card_id') && ! $this->base()->zones()->exists()) {
                    $validator->errors()->add('base_rate_card_id', 'These rates have no zones yet. Choose rates with zones.');
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
            'file.required' => 'Choose an Excel (.xlsx) or CSV file.',
            'file.file' => 'The file did not upload. Try again.',
            'file.uploaded' => 'The file did not upload. It may be larger than the server accepts; try a file under 5 MB.',
            'file.extensions' => 'Choose an Excel (.xlsx) or CSV file. Older .xls files need saving as .xlsx first.',
            'file.max' => 'The file is over 5 MB. Remove what is not prices, or split it.',
            'base_rate_card_id.integer' => 'Choose the rates whose zones to use.',
            'base_rate_card_id.exists' => 'Those rates no longer exist. Choose others.',
        ];
    }

    /**
     * Get the validated file.
     */
    public function spreadsheet(): UploadedFile
    {
        return $this->validated('file');
    }

    /**
     * Get the card whose zones the prices use: the one chosen, else the
     * current rates.
     */
    public function base(): RateCard
    {
        $id = $this->input('base_rate_card_id');

        return RateCard::query()->findOrFail(
            $id === null || $id === '' ? app(RateCards::class)->current()->id : (int) $id,
        );
    }

    /**
     * Get what is wrong with an .xlsx file's zip archive, if anything: it
     * is not a zip archive with a workbook in it, or it unpacks to far more
     * than a workbook of prices (in all, or one part for its size).
     */
    private static function workbookProblem(string $path): ?string
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return self::NOT_A_WORKBOOK;
        }

        try {
            if ($zip->locateName('[Content_Types].xml') === false || $zip->locateName('xl/workbook.xml') === false) {
                return self::NOT_A_WORKBOOK;
            }

            $unpacked = 0;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $part = $zip->statIndex($i);

                if ($part === false) {
                    return self::NOT_A_WORKBOOK;
                }

                $unpacked += $part['size'];
                $swells = $part['size'] > self::RATIO_FROM_BYTES && $part['size'] > $part['comp_size'] * self::MAX_PACKING_RATIO;

                if ($unpacked > self::MAX_UNPACKED_BYTES || $swells) {
                    return 'This workbook is far larger once opened than a sheet of prices. Keep only the sheet with the prices, or save it as .csv.';
                }
            }
        } finally {
            $zip->close();
        }

        return null;
    }
}
