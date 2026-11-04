<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\PsbDraft;
use App\Models\SupportTicket;
use App\Models\Transaction;
use App\Support\AreaScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SensitiveMediaController extends Controller
{
    public function customerKtp(Request $request, Customer $customer)
    {
        AreaScope::authorizeCustomer($customer, $request->user());

        return $this->serve($customer->getRawOriginal('ktp_photo_url'));
    }

    public function customerOdp(Request $request, Customer $customer)
    {
        AreaScope::authorizeCustomer($customer, $request->user());

        return $this->serve($customer->getRawOriginal('odp_photo_url'));
    }

    public function transactionProof(Request $request, Transaction $transaction)
    {
        $invoice = $transaction->invoice()->with(['customer' => fn ($query) => $query->withTrashed()])->firstOrFail();
        AreaScope::authorizeInvoice($invoice, $request->user());

        return $this->serve($transaction->getRawOriginal('proof_url'));
    }

    public function ticketPhoto(Request $request, SupportTicket $ticket, int $index)
    {
        AreaScope::authorizeTicket($ticket, $request->user());
        $photos = is_array($ticket->photos) ? array_values($ticket->photos) : [];

        abort_unless(array_key_exists($index, $photos), 404);

        return $this->serve($photos[$index]);
    }

    public function psbDraftPhoto(Request $request, PsbDraft $draft, string $kind)
    {
        abort_unless($request->user()?->isAdminLike(), 403);
        AreaScope::authorizePsbDraft($draft, $request->user());

        $path = match ($kind) {
            'ktp' => $draft->ktp_photo_path,
            'router' => $draft->router_photo_path,
            default => null,
        };

        abort_if($path === null, 404);

        return $this->serve($path);
    }

    private function serve(?string $value)
    {
        $path = trim((string) $value);
        abort_if($path === '' || filter_var($path, FILTER_VALIDATE_URL), 404);

        $path = str_replace('\\', '/', rawurldecode($path));
        $segments = explode('/', trim($path, '/'));
        abort_if(
            str_contains($path, "\0") || $path === '' || array_intersect($segments, ['.', '..']),
            404
        );
        $path = implode('/', $segments);

        foreach (['private', 'local', 'public'] as $diskName) {
            $disk = Storage::disk($diskName);
            if (! $disk->exists($path)) {
                continue;
            }

            return $disk->response($path, null, [
                'Cache-Control' => 'private, no-store',
                'Content-Disposition' => 'inline',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        abort(404);
    }
}
