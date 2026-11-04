<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OltRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $oltId = $this->route('olt')?->id;

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('olts', 'name')->ignore($oltId)],
            'code' => ['required', 'string', 'max:255', Rule::unique('olts', 'code')->ignore($oltId)],
            'vendor' => ['nullable', Rule::in(['zte_c300', 'hioso', 'huawei_ma5608t'])],
            'area_id' => ['nullable', 'exists:areas,id'],
            'router_id' => ['nullable', 'exists:routers,id'],
            'management_ip' => ['nullable', 'ip'],
            'management_protocol' => ['nullable', Rule::in(['ssh', 'telnet', 'snmp', 'http', 'https'])],
            'management_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'snmp_community' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
