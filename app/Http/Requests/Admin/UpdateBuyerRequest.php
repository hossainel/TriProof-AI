<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBuyerRequest extends FormRequest
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
        $buyer = $this->route('user');
        $buyerId = $buyer instanceof User ? $buyer->id : $buyer;

        return [
            'name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email', 'max:160', Rule::unique('users', 'email')->ignore($buyerId)],
            'password' => ['nullable', 'string', 'min:8', 'max:255'],
            'language_pref' => ['required', 'in:en,bn'],
        ];
    }
}
