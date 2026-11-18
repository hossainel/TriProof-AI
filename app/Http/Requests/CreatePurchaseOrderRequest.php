<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreatePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() || $this->user()?->isBuyer();
    }

    public function rules(): array
    {
        return [
            'po_number' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._\/-]+$/'],
            'buyer_id' => ['nullable', 'integer', 'exists:users,id'],
            'factory_id' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9][A-Za-z0-9-]{1,31}$/'],
            'authorized_quota' => ['required', 'integer', 'min:1', 'max:1000000'],
            'factory_expiry_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'retail_expiry_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ];
    }
}
