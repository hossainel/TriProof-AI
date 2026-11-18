<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GenerateBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() || $this->user()?->isBuyer();
    }

    public function rules(): array
    {
        $max = (int) config('triproof.exports.max_generate', 50000);

        return [
            'po_id' => ['required', 'integer', 'exists:purchase_orders,id'],
            'machine_id' => ['required', 'string', 'max:16', 'regex:/^[A-Za-z0-9][A-Za-z0-9-]{0,15}$/'],
            'batch_id' => ['required', 'string', 'max:16', 'regex:/^[A-Za-z0-9][A-Za-z0-9-]{0,15}$/'],
            'unit_start' => ['nullable', 'integer', 'min:1', 'max:999999'],
            'prod_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'count' => ['required', 'integer', 'min:1', "max:{$max}"],
            'include_units' => ['nullable', 'boolean'],
        ];
    }
}
