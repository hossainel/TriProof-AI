<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MarkNotificationsReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'device_token' => ['required', 'string', 'max:128'],
            'ids' => ['nullable', 'array', 'max:500'],
            'ids.*' => ['integer', 'min:1'],
            'mark_all' => ['nullable', 'boolean'],
        ];
    }
}
