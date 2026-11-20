<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreatePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() || $this->user()?->isBuyer();
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'factory_id' => strtoupper(trim((string) $this->input('factory_id'))),
        ]);
    }

    public function rules(): array
    {
        return [
            'po_number' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._\/-]+$/'],
            'buyer_id' => ['nullable', 'integer', 'exists:users,id'],
            'factory_id' => [
                'required',
                'string',
                'max:32',
                'regex:/^[A-Za-z0-9][A-Za-z0-9-]{1,31}$/',
                Rule::exists('factories', 'code')->where('is_active', true),
            ],
            'authorized_quota' => ['required', 'integer', 'min:1', 'max:1000000'],
            'factory_expiry_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'retail_expiry_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'dpp_metadata' => ['nullable', 'array', 'max:30'],
        ];
    }

    public function messages(): array
    {
        return [
            'factory_id.exists' => __('triproof.validation.factory_not_registered'),
        ];
    }
}
