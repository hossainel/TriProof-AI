<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PackBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() || $this->user()?->isFactory();
    }

    public function rules(): array
    {
        return [
            'po_id' => ['required', 'integer', 'exists:purchase_orders,id'],
            'machine_id' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9][A-Za-z0-9-]{0,31}$/'],
            'batch_id' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9][A-Za-z0-9-]{0,31}$/'],
            'unit_start' => ['nullable', 'integer', 'min:1', 'max:999999'],
            'unit_end' => ['nullable', 'integer', 'min:1', 'max:999999', 'gte:unit_start'],
        ];
    }
}
