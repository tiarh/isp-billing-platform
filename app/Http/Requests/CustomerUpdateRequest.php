<?php

namespace App\Http\Requests;

use App\Enums\CustomerStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CustomerUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'pppoe_password' => ['nullable', 'string', 'min:4', 'max:64'],
            'pppoe_local_address' => ['nullable', 'string', 'max:255'],
            'pppoe_remote_address' => ['nullable', 'string', 'max:255'],
            'package_id' => ['required', 'integer', 'exists:packages,id'],
            'area_id' => ['nullable', 'integer', 'exists:areas,id'],
            'router_id' => ['nullable', 'integer', 'exists:routers,id'],
            'olt_id' => ['nullable', 'integer', 'exists:olts,id'],
            'olt_port_label' => ['nullable', 'string', 'max:255'],
            'onu_serial' => ['nullable', 'string', 'max:255'],
            'olt_status' => ['nullable', 'string', 'max:50'],
            'onu_rx_power_dbm' => ['nullable', 'numeric', 'between:-100,100'],
            'onu_tx_power_dbm' => ['nullable', 'numeric', 'between:-100,100'],
            'fiber_distance_m' => ['nullable', 'integer', 'min:0'],
            'address' => ['required', 'string'],
            'phone' => ['nullable', 'string', 'max:20'],
            'nik' => ['nullable', 'string', 'max:20'],
            'status' => ['required', Rule::in(CustomerStatus::uiEditable())],
            'due_day' => ['nullable', 'integer', 'between:1,31'],
            'geo_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'geo_long' => ['nullable', 'numeric', 'between:-180,180'],
            'ktp_photo' => ['nullable', 'image', 'mimes:jpeg,png,jpg', 'max:2048', 'dimensions:min_width=100,min_height=100,max_width=6000,max_height=6000'],
        ];

        if ($this->user()?->isGlobalAdmin()) {
            $customer = $this->route('customer');
            $customerId = is_object($customer) ? $customer->getKey() : $customer;

            $rules['pppoe_user'] = [
                'nullable',
                'string',
                'max:255',
                Rule::unique('customers', 'pppoe_user')->ignore($customerId),
            ];
        }

        return $rules;
    }
}
