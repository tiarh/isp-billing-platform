<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RouterUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'ip_address' => ['required', 'ip'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'username' => ['required', 'string', 'max:255'],
            'password' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ];
    }
}
