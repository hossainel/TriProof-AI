<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class StoreFactoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => Str::upper(trim((string) $this->input('code'))),
            'name_en' => trim((string) $this->input('name_en')),
            'name_bn' => $this->filled('name_bn') ? trim((string) $this->input('name_bn')) : null,
            'location' => $this->filled('location') ? trim((string) $this->input('location')) : null,
            'contact_email' => $this->filled('contact_email') ? trim((string) $this->input('contact_email')) : null,
        ]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Z0-9][A-Z0-9-]{1,31}$/', 'unique:factories,code'],
            'name_en' => ['required', 'string', 'max:160'],
            'name_bn' => ['nullable', 'string', 'max:160'],
            'location' => ['nullable', 'string', 'max:160'],
            'contact_email' => ['nullable', 'email', 'max:160'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
