<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BroadcastStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('target_area_id') === '' || $this->input('target_area_id') === null) {
            $this->merge(['target_area_id' => null]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'target_type' => ['required', Rule::in(['all', 'active', 'isolated'])],
            'target_area_id' => ['nullable', 'integer', 'exists:areas,id'],
            'message_template' => ['required', 'string'],
        ];
    }
}
