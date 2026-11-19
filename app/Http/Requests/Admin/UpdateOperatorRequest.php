<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOperatorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $operator = $this->route('user');
        $operatorId = $operator instanceof User ? $operator->id : $operator;

        return [
            'name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email', 'max:160', Rule::unique('users', 'email')->ignore($operatorId)],
            'password' => ['nullable', 'string', 'min:8', 'max:255'],
            'factory_id' => ['required', 'string', 'exists:factories,code'],
            'language_pref' => ['required', 'in:en,bn'],
        ];
    }
}
