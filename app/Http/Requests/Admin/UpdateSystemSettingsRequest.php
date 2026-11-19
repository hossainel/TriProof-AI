<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSystemSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'organization_name' => ['required', 'string', 'max:120'],
            'support_email' => ['required', 'email', 'max:160'],
            'default_factory_expiry_days' => ['required', 'integer', 'min:1', 'max:365'],
            'default_retail_expiry_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'compliance_notice_en' => ['nullable', 'string', 'max:2000'],
            'compliance_notice_bn' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
