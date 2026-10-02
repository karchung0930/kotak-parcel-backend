<?php

namespace App\Http\Requests\Driver;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

class DeliverJobRequest extends FormRequest
{
    /**
     * Determine if the user is the driver assigned to this delivery.
     */
    public function authorize(): bool
    {
        return $this->user()->can('deliver', $this->route('order'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'recipient_name' => ['required', 'string', 'max:100'],
            // Checked by content, not file name; SVG is never accepted as an image.
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
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
            'recipient_name.required' => 'Enter the name of the person who received the parcel.',
            'photo.required' => 'Take a photo of the delivered parcel.',
        ];
    }

    /**
     * Get the validated proof of delivery photo.
     */
    public function photo(): UploadedFile
    {
        return $this->validated('photo');
    }
}
