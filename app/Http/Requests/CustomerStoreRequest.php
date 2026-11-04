<?php

namespace App\Http\Requests;

use App\Enums\CustomerStatus;
use App\Models\Customer;
use App\Models\RouterStagedCustomer;
use App\Services\Routers\UnlinkedSecretPrefill;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CustomerStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'address' => ['required', 'string'],
            'phone' => ['nullable', 'string', 'max:20'],
            'nik' => ['nullable', 'string', 'max:20'],
            'pppoe_user' => ['required', 'string', 'max:255', Rule::unique('customers', 'pppoe_user')],
            // Customer CRUD may not write a PPPoE password directly.  The
            // password must be created/verified on MikroTik first.
            'pppoe_password' => ['prohibited'],
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
            'status' => ['required', Rule::in(CustomerStatus::uiEditable())],
            'due_day' => ['nullable', 'integer', 'between:1,31'],
            'geo_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'geo_long' => ['nullable', 'numeric', 'between:-180,180'],
            'ktp_photo' => ['nullable', 'image', 'mimes:jpeg,png,jpg', 'max:2048', 'dimensions:min_width=100,min_height=100,max_width=6000,max_height=6000'],
            // Diisi hanya kalau form dibuka dari daftar "secret belum terhubung" di halaman router.
            'router_staged_customer_id' => ['nullable', 'integer'],
        ];
    }

    /** @return array<int, \Closure(Validator): void> */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->checkStagedSecret($validator)];
    }

    private function checkStagedSecret(Validator $validator): void
    {
        $stagedId = $this->input('router_staged_customer_id');
        if (blank($stagedId) || $validator->errors()->has('router_staged_customer_id')) {
            return;
        }

        $row = RouterStagedCustomer::query()->find((int) $stagedId);
        $same = $row !== null
            && $this->user()?->isGlobalAdmin()
            && (int) $row->router_id === (int) $this->input('router_id')
            && $row->pppoe_user === $this->input('pppoe_user');
        $refusal = $same ? app(UnlinkedSecretPrefill::class)->saveRefusal($row) : UnlinkedSecretPrefill::CHANGED_MESSAGE;
        if ($refusal !== null) {
            $validator->errors()->add('router_staged_customer_id', $refusal);

            return;
        }

        // Username dipakai pelanggan yang sudah dihapus: pesan yang jelas.
        if ($validator->errors()->has('pppoe_user')
            && Customer::onlyTrashed()->where('pppoe_user', $row->pppoe_user)->exists()) {
            $validator->errors()->forget('pppoe_user');
            $validator->errors()->add('pppoe_user', 'Username ini masih dipakai pelanggan lama (terhapus). Pulihkan pelanggan itu.');
        }
    }
}
