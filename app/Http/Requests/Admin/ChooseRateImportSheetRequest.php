<?php

namespace App\Http\Requests\Admin;

use App\Actions\RateImports\ReadRateImport;
use App\Models\RateImport;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Another sheet of the uploaded workbook to read, by name.
 */
class ChooseRateImportSheetRequest extends FormRequest
{
    /**
     * Determine if the user may work on this import.
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->import());
    }

    /**
     * Get the validation rules that apply to the request: one of the
     * workbook's sheets.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'sheet' => ['required', 'string', 'max:'.ReadRateImport::MAX_SHEET_NAME, Rule::in($this->import()->preview['sheets'] ?? [])],
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
            'sheet.*' => 'Choose one of the sheets in the file.',
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
