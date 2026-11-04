<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Invoice {{ $invoice->code }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            font-size: 10pt;
            line-height: 1.5;
            color: #333;
        }
        
        .container {
            padding: 20mm;
        }
        
        /* Header Section */
        .header {
            display: table;
            width: 100%;
            margin-bottom: 20px;
        }
        
        .header-left {
            display: table-cell;
            width: 50%;
            vertical-align: top;
        }
        
        .header-right {
            display: table-cell;
            width: 50%;
            vertical-align: top;
            text-align: right;
        }
        
        .logo {
            max-width: 180px;
            height: auto;
        }
        
        .invoice-meta {
            font-size: 9pt;
            line-height: 1.8;
        }
        
        .invoice-meta strong {
            font-weight: 600;
        }
        
        /* Company & Customer Section */
        .info-section {
            display: table;
            width: 100%;
            margin: 20px 0;
            border-top: 2px solid #e5e7eb;
            border-bottom: 2px solid #e5e7eb;
            padding: 15px 0;
        }
        
        .company-info {
            display: table-cell;
            width: 50%;
            vertical-align: top;
            padding-right: 20px;
        }
        
        .customer-info {
            display: table-cell;
            width: 50%;
            vertical-align: top;
            text-align: right;
        }
        
        .section-title {
            font-size: 11pt;
            font-weight: 700;
            margin-bottom: 8px;
            color: #1e40af;
        }
        
        .info-line {
            font-size: 9pt;
            line-height: 1.6;
            color: #4b5563;
        }
        
        /* Line Items Table */
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        
        .items-table th {
            background-color: #f3f4f6;
            padding: 10px;
            text-align: left;
            font-weight: 600;
            font-size: 10pt;
            border-bottom: 2px solid #d1d5db;
        }
        
        .items-table th.amount {
            text-align: right;
        }
        
        .items-table td {
            padding: 10px;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .items-table td.amount {
            text-align: right;
            font-weight: 500;
        }
        
        /* Total Section */
        .total-section {
            margin: 20px 0;
            padding: 15px;
            background-color: #f9fafb;
            border: 1px solid #e5e7eb;
            text-align: right;
        }
        
        .total-label {
            font-size: 11pt;
            font-weight: 700;
            display: inline-block;
            margin-right: 20px;
        }
        
        .total-amount {
            font-size: 14pt;
            font-weight: 700;
            color: #1e40af;
            display: inline-block;
        }
        
        /* Payment Methods */
        .payment-section {
            margin: 20px 0;
        }
        
        .payment-title {
            font-size: 11pt;
            font-weight: 700;
            margin-bottom: 10px;
            color: #1e40af;
        }
        
        .payment-subtitle {
            font-size: 10pt;
            font-weight: 600;
            margin: 15px 0 5px 0;
        }
        
        .payment-details {
            background-color: #f9fafb;
            padding: 10px;
            margin: 5px 0;
            border-left: 3px solid #1e40af;
            font-size: 9pt;
        }
        
        .account-number {
            font-family: 'Courier New', monospace;
            font-weight: 700;
            font-size: 10pt;
        }

        .pay-link-box {
            background-color: #eff6ff;
            border: 1px solid #93c5fd;
            border-left: 4px solid #1e40af;
            padding: 12px;
            margin: 10px 0 16px 0;
        }

        .pay-link {
            font-family: 'Courier New', monospace;
            font-size: 9pt;
            font-weight: 700;
            color: #1e3a8a;
            word-break: break-all;
        }

        .pay-hint {
            font-size: 8.5pt;
            color: #4b5563;
            margin-top: 4px;
        }
        
        /* Footer */
        .footer {
            margin-top: 40px;
            text-align: right;
        }
        
        .footer-date {
            font-size: 9pt;
            color: #6b7280;
            margin-bottom: 40px;
        }
        
        .footer-company {
            font-size: 10pt;
            font-weight: 600;
        }
        
        /* Utility Classes */
        .text-muted {
            color: #6b7280;
        }
        
        .text-bold {
            font-weight: 600;
        }
        
        .mb-small {
            margin-bottom: 5px;
        }
    </style>
</head>
<body>
    <div class="container">
        @php
            \Carbon\Carbon::setLocale('id');
        @endphp
        <!-- Header: Logo and Invoice Metadata -->
        <div class="header">
            <div class="header-left">
                @php
                    $logoPath = public_path('images/logo.png');
                    $logoData = base64_encode(file_get_contents($logoPath));
                    $logoSrc = 'data:image/png;base64,' . $logoData;
                @endphp
                <img src="{{ $logoSrc }}" alt="Skynet Logo" class="logo">
            </div>
            <div class="header-right">
                <div class="invoice-meta">
                    <div><strong>Invoice #:</strong> {{ $invoice->code }}</div>
                    <div><strong>Periode:</strong> {{ \Carbon\Carbon::parse($invoice->period)->translatedFormat('F Y') }}</div>
                    <div><strong>Jatuh Tempo:</strong> {{ \Carbon\Carbon::parse($invoice->due_date)->translatedFormat('d F Y') }}</div>
                    <div><strong>ID Pelanggan:</strong> {{ $invoice->customer->code }}</div>
                    <div><strong>Nama Pelanggan:</strong> {{ $invoice->customer->name }}</div>
                </div>
            </div>
        </div>

        <!-- ... (middle sections) ... -->

        <!-- Line Items -->
        <table class="items-table">
            <thead>
                <tr>
                    <th>Item</th>
                    <th class="amount">Harga</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>
                        <strong>{{ $invoice->customer->package->name }}</strong>
                        <div class="text-muted" style="font-size: 9pt;">
                            ({{ \Carbon\Carbon::parse($invoice->period)->translatedFormat('F Y') }})
                        </div>
                    </td>
                    <td class="amount">{{ number_format($invoice->amount, 0, ',', '.') }}</td>
                </tr>
            </tbody>
        </table>

        <!-- Total Section -->
        <div class="total-section">
            <span class="total-label">Total:</span>
            <span class="total-amount">Rp {{ number_format($invoice->amount, 0, ',', '.') }}</span>
        </div>

        @php
            $isPaid = ($invoice->status ?? '') === 'paid';
            $totalPaid = $invoice->totalPaid();
            $methodLabels = [
                'cash' => 'Tunai', 'transfer' => 'Transfer bank', 'qris' => 'QRIS',
                'va' => 'Virtual Account', 'ewallet' => 'E-wallet', 'retail' => 'Gerai retail',
                'card' => 'Kartu', 'other' => 'Lainnya',
            ];
            $providerLabels = ['duitku' => 'Duitku', 'midtrans' => 'Midtrans'];
            $paidRows = [];
            if ($totalPaid > 0) {
                foreach ($invoice->transactions->whereIn('status', \App\Models\Transaction::SETTLED_STATUSES) as $trx) {
                    $label = $methodLabels[(string) $trx->method] ?? ucfirst((string) ($trx->method ?: 'Lainnya'));
                    if (($trx->channel ?? '') === 'gateway') {
                        $parts = explode(':', (string) $trx->idempotency_key);
                        $provider = $providerLabels[$parts[1] ?? ''] ?? null;
                        $label .= $provider ? ' via '.$provider : ' (pembayaran online)';
                    }
                    $net = (float) $trx->amount - (float) $trx->reversals->sum('amount');
                    if ($net <= 0) {
                        continue;
                    }
                    $paidRows[] = [
                        'amount' => $net,
                        'date' => $trx->paid_at ? $trx->paid_at->copy()->timezone('Asia/Jakarta')->translatedFormat('d F Y') : null,
                        'method' => $label,
                        'credit' => $trx->isCreditApplication(),
                    ];
                }
            }
            $accounts = is_array($manual_accounts ?? null) ? $manual_accounts : [];
            $showManual = ! $isPaid && count($accounts) > 0;
            $showOnline = ! $isPaid && ! empty($payment_url);
        @endphp

        @if($totalPaid > 0)
        <div class="payment-section">
            <div class="payment-title">Ringkasan Pembayaran:</div>
            @foreach($paidRows as $row)
            <div class="payment-details" style="border-left-color:#059669;background:#ecfdf5;">
                @if($row['credit'])
                <div class="text-bold" style="color:#047857;">Saldo (kelebihan bayar): Rp {{ number_format($row['amount'], 0, ',', '.') }}{{ $row['date'] ? ' — '.$row['date'] : '' }}</div>
                @else
                <div class="text-bold" style="color:#047857;">Dibayar: Rp {{ number_format($row['amount'], 0, ',', '.') }}{{ $row['date'] ? ' — '.$row['date'] : '' }} — {{ $row['method'] }}</div>
                @endif
            </div>
            @endforeach
            @if(! $isPaid && $invoice->amount - $totalPaid > 0)
            <div class="pay-hint">Sisa tagihan: Rp {{ number_format($invoice->amount - $totalPaid, 0, ',', '.') }}</div>
            @endif
        </div>
        @endif

        @if($showOnline || $showManual)
        <!-- Payment Methods -->
        <div class="payment-section">
            <div class="payment-title">Info Pembayaran:</div>

            @if($showOnline)
            <div class="payment-subtitle">1) Bayar online (QRIS / VA / e-wallet / kartu)</div>
            <div class="pay-link-box">
                <div class="text-bold mb-small">Link pembayaran (klik langsung):</div>
                {{-- DomPDF: <a href> = clickable di viewer PDF --}}
                <div class="pay-link">
                    <a href="{{ $payment_url }}" style="color:#1e3a8a; text-decoration:underline; font-weight:700; font-family:'Courier New',monospace; font-size:9pt;">
                        {{ $payment_url }}
                    </a>
                </div>
                <div class="pay-hint" style="margin-top:8px;">
                    Atau scan / buka URL di atas di browser HP.
                    Setelah lunas (Midtrans / konfirmasi admin), isolir dibuka otomatis.
                </div>
            </div>
            @endif

            @if($showManual)
            <div class="payment-subtitle">{{ $showOnline ? '2) ' : '' }}Transfer bank manual</div>
                @foreach($accounts as $account)
                <div class="payment-details">
                    <div class="text-bold">{{ $account['bank'] ?? $account['name'] ?? 'Bank' }} A/N {{ $account['account_name'] ?? $company['name'] }}</div>
                    <div class="account-number">{{ $account['account_number'] ?? $account['number'] ?? '-' }}</div>
                </div>
                @endforeach
            @endif
        </div>
        @endif

        @if($isPaid)
        <div class="payment-section">
            <div class="payment-details" style="border-left-color:#059669;background:#ecfdf5;">
                <div class="text-bold" style="color:#047857;">Status: LUNAS</div>
                <div class="pay-hint">Pembayaran sudah diterima. Terima kasih.</div>
            </div>
        </div>
        @endif

        <!-- Footer -->
        <div class="footer">
            <div class="footer-date">{{ \Carbon\Carbon::parse($invoice->period)->translatedFormat('F Y') }}</div>
            <div class="footer-company">{{ $company['name'] }}</div>
        </div>
    </div>
</body>
</html>
