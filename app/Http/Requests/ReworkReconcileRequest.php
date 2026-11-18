<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReworkReconcileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() || $this->user()?->isFactory();
    }

    public function rules(): array
    {
        return [
            'entries' => ['required', 'array', 'min:1', 'max:1000'],
            'entries.*.parent_outer_serial' => ['nullable', 'string', 'max:128'],
            'entries.*.child_outer_serial' => ['nullable', 'string', 'max:128'],
            'entries.*.action' => ['nullable', 'string', 'max:32'],
            'entries.*.line_id' => ['nullable', 'string', 'max:64'],
            'entries.*.note' => ['nullable', 'string', 'max:500'],
            'entries.*.scanned_at' => ['nullable', 'date'],
            'entries.*.image_base64' => ['nullable', 'string', 'max:7000000'],
            'entries.*.image_mime' => ['nullable', 'in:image/jpeg,image/png,image/webp'],
        ];
    }
}
