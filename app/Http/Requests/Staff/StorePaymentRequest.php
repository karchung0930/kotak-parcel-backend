<?php

namespace App\Http\Requests\Staff;

use App\Enums\PaymentMethod;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePaymentRequest extends FormRequest
{
    /**
     * Determine if the user may take payment at the counter.
     */
    public function authorize(): bool
    {
        return $this->user()->can('process', $this->route('order'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * The card reference is the terminal's approval code. Its length limit
     * also rejects card numbers (13 to 19 digits), which are never stored.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'amount_sen' => ['required', 'integer', 'min:1'],
            'reference' => ['exclude_unless:method,card', 'required', 'string', 'regex:/^[A-Za-z0-9]{4,12}$/'],
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
            'reference.required' => 'Enter the approval code from the card terminal.',
            'reference.regex' => 'Enter the 4 to 12 character approval code from the terminal slip, never the card number.',
        ];
    }

    /**
     * Get the validated payment method.
     */
    public function paymentMethod(): PaymentMethod
    {
        return PaymentMethod::from($this->string('method')->value());
    }
}
