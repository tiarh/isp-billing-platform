<?php

namespace App\Http\Requests;

use App\Support\OpsTime;
use Carbon\Carbon;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PaymentStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'invoice_confirmation' => ['required', 'string', 'max:191'],
            'customer_confirmation' => ['required', 'string', 'max:191'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', Rule::in(['cash', 'transfer', 'qris', 'other'])],
            'proof' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:2048', 'dimensions:min_width=100,min_height=100,max_width=6000,max_height=6000'],
            'paid_at' => self::paidAtRules(),
            'idempotency_key' => ['nullable', 'string', 'max:191'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Shared paid_at rules (single store, CSV import, bulk). A payment date whose
     * WIB calendar day is after today is refused; back-dating stays allowed.
     * Parsing is shared with the controller through parsePaidAt().
     *
     * @return array<int, mixed>
     */
    public static function paidAtRules(): array
    {
        return [
            'nullable',
            'date',
            function (string $attribute, mixed $value, Closure $fail): void {
                if ($value === null || trim((string) $value) === '') {
                    return;
                }

                $parsed = self::parsePaidAt($value);
                if ($parsed === null) {
                    return; // the `date` rule reports unparseable input
                }

                if ($parsed->timezone(OpsTime::TZ)->toDateString() > OpsTime::now()->toDateString()) {
                    $fail('Tanggal bayar tidak boleh di masa depan.');
                }
            },
        ];
    }

    /**
     * Single parser for paid_at (all times in eBilling are WIB; storage is UTC).
     *  - a string with an offset or Z is parsed as given;
     *  - a string WITHOUT a timezone (CSV, bulk, older clients) is WIB;
     *  - a date-only string is that WIB day at 12:00 WIB (05:00 UTC, the same calendar day in UTC and WIB), except today's date,
     *    which means "now" (so a same-day payment is not back-dated to midnight).
     * The result is in UTC, ready to store. Returns null if unparseable.
     */
    public static function parsePaidAt(mixed $value): ?Carbon
    {
        $string = trim((string) $value);
        if ($string === '') {
            return null;
        }

        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $string) === 1 && $string === OpsTime::now()->toDateString()) {
                return Carbon::now()->utc();
            }

            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $string) === 1) {
                // Past date-only: noon WIB (= 05:00 UTC) keeps the same calendar day in UTC and WIB.
                return Carbon::parse($string.' 12:00:00', OpsTime::TZ)->utc();
            }

            return Carbon::parse($string, OpsTime::TZ)->utc();
        } catch (\Throwable) {
            return null;
        }
    }
}
