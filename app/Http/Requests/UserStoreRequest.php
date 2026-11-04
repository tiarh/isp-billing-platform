<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;

class UserStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'role' => ['required', Rule::in(['superadmin', 'admin', 'noc', 'teknisi'])],
            'phone' => ['nullable', 'string', 'max:30'],
            'area_ids' => ['array'],
            'area_ids.*' => ['integer', 'exists:areas,id'],
        ];
    }
}
