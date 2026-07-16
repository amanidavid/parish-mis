<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $invoice->invoice_number }}</title>
    <style>
        @page { margin: 26px 30px; }
        body {
            font-family: DejaVu Sans, sans-serif;
            color: #111827;
            margin: 0;
            font-size: 13px;
            line-height: 1.45;
        }
        .page { width: 100%; }
        .top, .meta, .items, .totals, .footer-table { width: 100%; border-collapse: collapse; }
        .top td, .meta td, .footer-table td { vertical-align: top; }
        .title {
            font-size: 38px;
            font-weight: bold;
            color: #111827;
            margin: 0 0 10px;
        }
        .brand {
            text-align: right;
            font-size: 28px;
            font-weight: bold;
            color: #111827;
            margin-bottom: 10px;
        }
        .brand-sub {
            text-align: right;
            font-size: 12px;
            color: #4b5563;
            line-height: 1.6;
        }
        .section-gap { height: 26px; }
        .rule {
            height: 1px;
            background: #dbe3ef;
            margin: 20px 0;
        }
        .status-badge {
            display: inline-block;
            padding: 5px 11px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: bold;
            letter-spacing: 0.4px;
            text-transform: uppercase;
        }
        .status-paid { background: #dcfce7; color: #166534; }
        .status-unpaid { background: #fef3c7; color: #92400e; }
        .status-overdue { background: #fee2e2; color: #991b1b; }
        .section-title {
            font-size: 16px;
            font-weight: bold;
            color: #2563eb;
            margin: 0 0 12px;
        }
        .meta-table {
            width: 100%;
            border-collapse: collapse;
        }
        .meta-table td {
            padding: 5px 0;
        }
        .meta-label {
            width: 40%;
            font-size: 12px;
            font-weight: bold;
            color: #4b5563;
        }
        .meta-value {
            font-size: 13px;
            color: #111827;
        }
        .amount-block {
            text-align: right;
        }
        .amount-label {
            font-size: 12px;
            font-weight: bold;
            color: #4b5563;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .amount-value {
            font-size: 30px;
            font-weight: bold;
            color: #111827;
            margin: 8px 0 4px;
        }
        .amount-meta {
            font-size: 13px;
            color: #4b5563;
        }
        .bill-label {
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            color: #6b7280;
            margin-bottom: 5px;
        }
        .bill-value {
            font-size: 14px;
            color: #111827;
            margin-bottom: 8px;
        }
        .items {
            border-top: 1px solid #d1d5db;
        }
        .items th {
            background: #f8fafc;
            color: #374151;
            font-size: 13px;
            font-weight: bold;
            text-align: left;
            padding: 12px 10px;
            border-bottom: 1px solid #d1d5db;
        }
        .items td {
            font-size: 13px;
            color: #111827;
            padding: 12px 10px;
            border-bottom: 1px solid #e5e7eb;
            vertical-align: top;
        }
        .items .center { text-align: center; }
        .items .right { text-align: right; }
        .totals-wrap {
            width: 330px;
            margin-left: auto;
        }
        .totals td {
            padding: 6px 0;
            font-size: 13px;
            color: #111827;
        }
        .totals .label {
            color: #4b5563;
        }
        .totals .value {
            text-align: right;
            font-weight: bold;
        }
        .totals .amount-due td {
            padding-top: 12px;
            font-size: 20px;
            font-weight: bold;
            border-top: 1px solid #d1d5db;
        }
        .support {
            margin-top: 24px;
            font-size: 12px;
            color: #4b5563;
        }
        .support strong {
            color: #111827;
        }
        .footer {
            margin-top: 26px;
            padding-top: 12px;
            border-top: 1px solid #dbe3ef;
            font-size: 11px;
            color: #6b7280;
        }
    </style>
</head>
<body>
    @php
        $statusClass = match($invoice->status) {
            'paid' => 'status-paid',
            'overdue' => 'status-overdue',
            default => 'status-unpaid',
        };
        $statusLabel = strtoupper((string) $invoice->status);
        $money = fn (int $cents, string $currency) => sprintf('%s %s', $currency, number_format($cents));
        $issueDate = $invoice->issue_date?->format('M j, Y');
        $dueDate = $invoice->due_date?->format('M j, Y');
        $periodLabel = trim(implode(' - ', array_filter([
            $invoice->period_starts_on?->format('M j, Y'),
            $invoice->period_ends_on?->format('M j, Y'),
        ])));
    @endphp

    <div class="page">
        <table class="top">
            <tr>
                <td style="width: 52%;">
                    <div class="title">Invoice</div>
                    <span class="status-badge {{ $statusClass }}">{{ $statusLabel }}</span>
                </td>
                <td style="width: 48%;">
                    <div class="brand">ZABA</div>
                    <div class="brand-sub">
                        ZABA AFRICA<br>
                        Property Management Platform<br>
                        Sinza, Dar es Salaam, Tanzania<br>
                        admin@zaba.africa<br>
                        https://zaba.africa
                    </div>
                </td>
            </tr>
        </table>

        <div class="section-gap"></div>

        <table class="meta">
            <tr>
                <td style="width: 52%; padding-right: 18px;">
                    <div class="section-title">Invoice Details</div>
                    <table class="meta-table">
                        <tr>
                            <td class="meta-label">Invoice No</td>
                            <td class="meta-value">{{ $invoice->invoice_number }}</td>
                        </tr>
                        <tr>
                            <td class="meta-label">Issue Date</td>
                            <td class="meta-value">{{ $issueDate }}</td>
                        </tr>
                        <tr>
                            <td class="meta-label">Due Date</td>
                            <td class="meta-value">{{ $dueDate }}</td>
                        </tr>
                        <tr>
                            <td class="meta-label">Status</td>
                            <td class="meta-value">{{ $statusLabel }}</td>
                        </tr>
                    </table>
                </td>
                <td style="width: 48%; padding-left: 18px;">
                    <div class="amount-block">
                        <div class="amount-label">Amount Due</div>
                        <div class="amount-value">{{ $money((int) $invoice->balance_amount_cents, $invoice->currency) }}</div>
                        <div class="amount-meta">Due {{ $dueDate }}</div>
                    </div>
                </td>
            </tr>
        </table>

        <div class="rule"></div>

        <table class="meta">
            <tr>
                <td style="width: 50%; padding-right: 18px;">
                    <div class="section-title">Bill To</div>
                    <div class="bill-label">Customer Name</div>
                    <div class="bill-value">{{ data_get($invoice->meta, 'tenant_full_name', $tenantName) }}</div>

                    <div class="bill-label">Phone</div>
                    <div class="bill-value">{{ data_get($invoice->meta, 'tenant_phone', '-') }}</div>

                    <div class="bill-label">Email</div>
                    <div class="bill-value">{{ data_get($invoice->meta, 'tenant_email', '-') }}</div>
                </td>
                <td style="width: 50%; padding-left: 18px;">
                    <div class="section-title">Property Billing</div>
                    <div class="bill-label">Property</div>
                    <div class="bill-value">{{ $invoice->workspaceProperty?->property_name ?: '-' }}</div>

                    <div class="bill-label">Payment Period</div>
                    <div class="bill-value">{{ $periodLabel ?: '-' }}</div>

                    <div class="bill-label">Currency</div>
                    <div class="bill-value">{{ $invoice->currency }}</div>
                </td>
            </tr>
        </table>

        <div class="section-gap"></div>

        <table class="items">
            <thead>
                <tr>
                    <th style="width: 36%;">Description</th>
                    <th style="width: 27%;">Payment Period</th>
                    <th class="center" style="width: 12%;">Unit Qty</th>
                    <th class="right" style="width: 12%;">Unit Price</th>
                    <th class="right" style="width: 13%;">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($invoice->items as $lineItem)
                    <tr>
                        <td>{{ $lineItem->description }}</td>
                        <td>{{ $lineItem->payment_period_label }}</td>
                        <td class="center">{{ $lineItem->quantity }}</td>
                        <td class="right">{{ $money((int) $lineItem->unit_price_cents, $invoice->currency) }}</td>
                        <td class="right">{{ $money((int) $lineItem->amount_cents, $invoice->currency) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="section-gap"></div>

        <div class="totals-wrap">
            <table class="totals">
                <tr>
                    <td class="label">Subtotal</td>
                    <td class="value">{{ $money((int) $invoice->subtotal_amount_cents, $invoice->currency) }}</td>
                </tr>
                <tr>
                    <td class="label">Total</td>
                    <td class="value">{{ $money((int) $invoice->total_amount_cents, $invoice->currency) }}</td>
                </tr>
                <tr class="amount-due">
                    <td>Amount Due</td>
                    <td class="value">{{ $money((int) $invoice->balance_amount_cents, $invoice->currency) }}</td>
                </tr>
            </table>
        </div>

        <div class="support">
            Thank you for choosing <strong>ZABA</strong>. For billing support or invoice clarification, contact
            <strong>admin@zaba.africa</strong> or visit <strong>https://zaba.africa</strong>.
        </div>

        <div class="footer">
            Generated by ZABA Property Management • https://zaba.africa • admin@zaba.africa
        </div>
    </div>
</body>
</html>
