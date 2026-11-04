<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RouterVpnUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'vpn_enabled' => ['boolean'],
            'vpn_interface' => ['nullable', 'string', 'max:64'],
            'vpn_address' => ['nullable', 'string', 'max:64'],
            'vpn_server_address' => ['nullable', 'string', 'max:64'],
            'vpn_server_public_key' => ['nullable', 'string', 'max:255'],
            'vpn_server_endpoint' => ['nullable', 'string', 'max:255'],
            'vpn_server_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'vpn_allowed_ips' => ['nullable', 'string', 'max:255'],
            'radius_enabled' => ['boolean'],
            'radius_secret' => ['nullable', 'string', 'max:255'],
            'radius_auth_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'radius_acct_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'generate_client_keys' => ['boolean'],
        ];
    }
}
