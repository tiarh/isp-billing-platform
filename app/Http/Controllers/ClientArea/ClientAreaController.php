<?php

namespace App\Http\Controllers\ClientArea;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerPortalAccount;
use App\Models\Invoice;
use App\Models\Setting;
use App\Services\BillingReceiptService;
use App\Services\PaymentGateway\Duitku\DuitkuConfig;
use App\Services\PaymentGateway\PaymentGatewayManager;
use App\Services\PaymentGateway\PaymentGatewaySettings;
use App\Support\CompanyContact;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ClientAreaController extends Controller
{
    private const GENERIC_FAIL = 'Kode pelanggan atau kata sandi salah, atau akun terkunci sementara.';

    public function showLogin(): Response|RedirectResponse
    {
        if (Auth::guard('customer')->check()) {
            return redirect()->route('client-area.dashboard');
        }

        return Inertia::render('ClientArea/Login', [
            'company' => $this->company(),
            'sandbox' => $this->isSandbox(),
        ]);
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'password' => ['required', 'string', 'max:200'],
        ]);

        $code = trim($data['code']);
        $ipKey = 'client-area-login:ip:'.$request->ip();
        $codeKey = 'client-area-login:code:'.Str::lower($code);
        $decay = (int) config('client_area.throttle_decay', 60);

        if (RateLimiter::tooManyAttempts($ipKey, (int) config('client_area.throttle_ip_attempts', 20))
            || RateLimiter::tooManyAttempts($codeKey, (int) config('client_area.throttle_code_attempts', 10))) {
            return back()
                ->withInput($request->only('code'))
                ->withErrors(['code' => 'Terlalu banyak percobaan masuk. Coba lagi beberapa menit lagi.']);
        }

        RateLimiter::hit($ipKey, $decay);
        RateLimiter::hit($codeKey, $decay);

        $customer = Customer::query()->where('code', $code)->first();
        $account = $customer
            ? CustomerPortalAccount::query()->where('customer_id', $customer->id)->first()
            : null;

        $usable = $account !== null && $account->is_active;

        // Exactly one Hash::check on every path (unknown, inactive, locked,
        // wrong, right) so response time does not reveal account state.
        $passwordOk = Hash::check($data['password'], $usable ? (string) $account->password : $this->dummyHash());

        if (! $usable || $account->isLocked()) {
            return $this->fail($request);
        }

        if (! $passwordOk) {
            $attempts = $account->failed_attempts + 1;
            $account->failed_attempts = $attempts;
            if ($attempts >= max(1, (int) config('client_area.lockout_attempts', 5))) {
                $account->locked_until = now()->addMinutes((int) config('client_area.lockout_minutes', 15));
                $account->failed_attempts = 0;
            }
            $account->save();

            return $this->fail($request);
        }

        $account->forceFill([
            'failed_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => now(),
        ])->save();

        RateLimiter::clear($codeKey);
        Auth::guard('customer')->login($account);
        $request->session()->regenerate();

        return redirect()->route('client-area.dashboard');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('customer')->logout();
        // Do not invalidate the whole session: a staff (web) login in the same
        // browser must keep working.
        $request->session()->regenerate();
        $request->session()->regenerateToken();

        return redirect()->route('client-area.login');
    }

    public function dashboard(Request $request): Response|RedirectResponse
    {
        /** @var CustomerPortalAccount|null $account */
        $account = Auth::guard('customer')->user();
        $customer = $account?->is_active ? $account->customer : null;

        if ($customer === null) {
            Auth::guard('customer')->logout();

            return redirect()->route('client-area.login');
        }

        $receipts = app(BillingReceiptService::class);

        $invoices = Invoice::query()
            ->where('customer_id', $customer->id)
            ->orderByDesc('period')
            ->orderByDesc('id')
            ->limit(24)
            ->get()
            ->map(function (Invoice $invoice) use ($receipts): array {
                $payable = in_array($invoice->status, ['unpaid', 'overdue'], true);

                // Explicit public DTO (same spirit as PublicInvoiceController): no internal ids.
                return [
                    'uuid' => (string) $invoice->uuid,
                    'code' => (string) $invoice->code,
                    'period' => $invoice->period?->toDateString(),
                    'amount' => (string) $invoice->amount,
                    'status' => (string) $invoice->status,
                    'due_date' => $invoice->due_date?->toDateString(),
                    'pay_url' => $payable ? route('public.invoice.show', $invoice->uuid) : null,
                    'receipt_url' => $invoice->status === 'paid' ? $receipts->url($invoice) : null,
                ];
            })
            ->values()
            ->all();

        return Inertia::render('ClientArea/Dashboard', [
            'customer' => [
                'code' => (string) $customer->code,
                'name' => (string) $customer->name,
            ],
            'invoices' => $invoices,
            'company' => $this->company(),
            'sandbox' => $this->isSandbox((int) $customer->id),
        ]);
    }

    public function terms(): Response
    {
        return Inertia::render('ClientArea/Terms', [
            'legal' => $this->legal(),
            'company' => $this->company(),
            'sandbox' => $this->isSandbox(),
        ]);
    }

    public function refund(): Response
    {
        return Inertia::render('ClientArea/Refund', [
            'legal' => $this->legal(),
            'company' => $this->company(),
            'sandbox' => $this->isSandbox(),
        ]);
    }

    private function fail(Request $request): RedirectResponse
    {
        return back()
            ->withInput($request->only('code'))
            ->withErrors(['code' => self::GENERIC_FAIL]);
    }

    private function dummyHash(): string
    {
        return Cache::rememberForever('client-area:dummy-hash', fn (): string => Hash::make(Str::random(32)));
    }

    /** Same condition as the /pay page banner: Duitku is the active online provider and in sandbox. */
    private function isSandbox(?int $customerId = null): bool
    {
        $gateways = app(PaymentGatewayManager::class);

        return $gateways->isActive('duitku')
            && $gateways->onlineAvailable($customerId)
            && app(DuitkuConfig::class)->environment() === DuitkuConfig::ENV_SANDBOX;
    }

    /**
     * @return array{draft: bool, fee_bearer: string}
     */
    private function legal(): array
    {
        return [
            'draft' => ! (bool) config('client_area.legal_approved'),
            'fee_bearer' => app(PaymentGatewaySettings::class)->feeBearer(),
        ];
    }

    /**
     * @return array{name: string, phone: string, email: string}
     */
    private function company(): array
    {
        return [
            'name' => (string) Setting::get('company_name', 'Skynet Network'),
            'phone' => CompanyContact::phone(),
            'email' => CompanyContact::email(),
        ];
    }
}
