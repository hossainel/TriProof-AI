<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RecallTriggerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'factory_id' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9][A-Za-z0-9-]{1,31}$/'],
            'machine_id' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9][A-Za-z0-9-]{0,31}$/'],
            'batch_id' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9][A-Za-z0-9-]{0,31}$/'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
