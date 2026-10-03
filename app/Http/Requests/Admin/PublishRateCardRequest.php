<?php

namespace App\Http\Requests\Admin;

use App\Actions\RateCards\PublishRateCard;
use App\Models\RateCard;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * When a draft takes effect: now, or at a date and time in Malaysia
 * (an <input type="datetime-local">, e.g. "2026-11-01T00:00").
 */
class PublishRateCardRequest extends FormRequest
{
    /**
     * Determine if the user may publish rate cards. Whether this one is
     * still a draft is for PublishRateCard to say, so a second publish of
     * the same draft is told why, rather than refused outright.
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
        return [
            'when' => ['required', Rule::in(['now', 'scheduled'])],
            'effective_at' => ['exclude_unless:when,scheduled', 'required', 'date_format:Y-m-d\TH:i'],
        ];
    }

    /**
     * Get the check that a scheduled time is from this minute on and within
     * two years (PublishRateCard::timeProblem()).
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

                $effectiveFrom = $this->effectiveFrom();
                $problem = $effectiveFrom === null ? null : PublishRateCard::timeProblem($effectiveFrom);

                if ($problem !== null) {
                    $validator->errors()->add('effective_at', $problem);
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
            'when.*' => 'Choose when the new rates take effect.',
            'effective_at.*' => 'Choose a date and time.',
        ];
    }

    /**
     * Get the moment the card takes effect, or null for straight away.
     */
    public function effectiveFrom(): ?CarbonImmutable
    {
        if ($this->input('when') !== 'scheduled') {
            return null;
        }

        $local = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', (string) $this->input('effective_at'), config()->string('kotak.timezone'));

        return $local instanceof CarbonImmutable ? $local->utc() : null;
    }
}
