<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

class SettingsUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'settings' => ['required', 'array'],
            'settings.*.key' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail) {
                // Grafana URL/token are managed only by the dedicated Grafana configuration path.
                if (str_starts_with(strtolower(trim((string) $value)), 'grafana_')) {
                    $fail('This setting cannot be changed here.');
                }
            }],
            'settings.*.value' => ['nullable'],
            'settings.*.group' => ['required', 'string'],
            'settings.*.type' => ['required', 'string'],
        ];
    }
}
