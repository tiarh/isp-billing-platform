<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;

class UserUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $userId = $this->route('user')?->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'password' => ['nullable', 'confirmed', Rules\Password::defaults()],
            'role' => ['required', Rule::in(['superadmin', 'admin', 'noc', 'teknisi'])],
            'phone' => ['nullable', 'string', 'max:30'],
            'area_ids' => ['array'],
            'area_ids.*' => ['integer', 'exists:areas,id'],
        ];
    }
}
