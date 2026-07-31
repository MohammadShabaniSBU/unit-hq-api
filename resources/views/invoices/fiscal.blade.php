{{-- Snapshot-only payload — never Eloquent models. --}}
@php
    /** @var array $invoice */
    $issuer = $invoice['issuer'] ?? [];
    $buyer = $invoice['buyer'] ?? [];
    $issuerAddress = $issuer['address'] ?? [];
    $buyerAddress = is_array($buyer['address'] ?? null) ? $buyer['address'] : null;
    $labels = $invoice['labels'] ?? [];
    $isSimplified = ($invoice['kind'] ?? '') === 'simplified';
@endphp
<!DOCTYPE html>
<html lang="{{ $invoice['locale'] ?? 'en' }}">
<head>
    <meta charset="utf-8">
    <title>{{ $invoice['full_number'] ?? '' }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111; margin: 32px; }
        h1 { font-size: 20px; margin: 0 0 8px; }
        .meta { margin-bottom: 24px; }
        .meta strong { font-size: 16px; }
        .cols { width: 100%; margin-bottom: 24px; }
        .cols td { vertical-align: top; width: 50%; }
        .box { border: 1px solid #ccc; padding: 10px; min-height: 80px; }
        .label { font-size: 10px; text-transform: uppercase; color: #555; margin-bottom: 4px; }
        table.lines { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        table.lines th, table.lines td { border-bottom: 1px solid #ddd; padding: 6px 4px; text-align: left; }
        table.lines th.num, table.lines td.num { text-align: right; }
        .totals { width: 280px; margin-left: auto; }
        .totals td { padding: 4px; }
        .totals .grand { font-weight: bold; font-size: 14px; }
        .qr { margin-top: 28px; width: 96px; height: 96px; border: 1px dashed #999; text-align: center; font-size: 9px; color: #777; padding-top: 36px; }
    </style>
</head>
<body>
    <div class="meta">
        <h1>{{ $invoice['kind_label'] ?? '' }}</h1>
        <div><strong>{{ $labels['number'] ?? 'Number' }}:</strong> {{ $invoice['full_number'] ?? '' }}</div>
        <div><strong>{{ $labels['issue_date'] ?? 'Date' }}:</strong> {{ $invoice['issue_date'] ?? '' }}</div>
    </div>

    <table class="cols">
        <tr>
            <td>
                <div class="label">{{ $labels['issuer'] ?? 'Issuer' }}</div>
                <div class="box">
                    <div><strong>{{ $issuer['name'] ?? '' }}</strong></div>
                    <div>{{ $issuer['tax_id'] ?? '' }}</div>
                    <div>{{ $issuerAddress['line1'] ?? '' }}</div>
                    @if (!empty($issuerAddress['line2']))
                        <div>{{ $issuerAddress['line2'] }}</div>
                    @endif
                    <div>{{ trim(($issuerAddress['postal'] ?? '').' '.($issuerAddress['city'] ?? '')) }}</div>
                    <div>{{ $issuerAddress['country'] ?? '' }}</div>
                </div>
            </td>
            <td>
                <div class="label">{{ $isSimplified ? ($invoice['kind_label'] ?? '') : ($labels['buyer'] ?? 'Buyer') }}</div>
                <div class="box">
                    @if ($isSimplified)
                        <div>{{ $buyer['name'] ?? '' }}</div>
                    @else
                        <div><strong>{{ $buyer['name'] ?? '' }}</strong></div>
                        <div>{{ $buyer['tax_id'] ?? '' }}</div>
                        @if ($buyerAddress)
                            <div>{{ $buyerAddress['line1'] ?? '' }}</div>
                            @if (!empty($buyerAddress['line2']))
                                <div>{{ $buyerAddress['line2'] }}</div>
                            @endif
                            <div>{{ trim(($buyerAddress['postal'] ?? '').' '.($buyerAddress['city'] ?? '')) }}</div>
                            <div>{{ $buyerAddress['country'] ?? '' }}</div>
                        @endif
                    @endif
                </div>
            </td>
        </tr>
    </table>

    <table class="lines">
        <thead>
            <tr>
                <th>{{ $labels['description'] ?? 'Description' }}</th>
                <th>{{ $labels['period'] ?? 'Period' }}</th>
                <th class="num">{{ $labels['net'] ?? 'Net' }}</th>
                <th class="num">{{ $labels['tax'] ?? 'Tax' }}</th>
                <th class="num">{{ $labels['total'] ?? 'Total' }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach (($invoice['lines'] ?? []) as $line)
                <tr>
                    <td>{{ $line['description'] ?? '' }}</td>
                    <td>
                        @if (!empty($line['period_start']) && !empty($line['period_end']))
                            {{ $line['period_start'] }} – {{ $line['period_end'] }}
                        @endif
                    </td>
                    <td class="num">{{ $line['net_amount'] ?? '' }} {{ $invoice['currency'] ?? '' }}</td>
                    <td class="num">{{ $line['tax_amount'] ?? '' }} ({{ $line['tax_rate_snapshot'] ?? '0' }}%)</td>
                    <td class="num">{{ $line['gross_amount'] ?? '' }} {{ $invoice['currency'] ?? '' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td>{{ $labels['net'] ?? 'Net' }}</td>
            <td class="num">{{ $invoice['net_total'] ?? '' }} {{ $invoice['currency'] ?? '' }}</td>
        </tr>
        @foreach (($invoice['tax_breakdown'] ?? []) as $tax)
            <tr>
                <td>{{ $labels['tax'] ?? 'Tax' }} {{ $tax['rate'] ?? '' }}%</td>
                <td class="num">{{ $tax['tax'] ?? '' }} {{ $invoice['currency'] ?? '' }}</td>
            </tr>
        @endforeach
        <tr class="grand">
            <td>{{ $labels['total'] ?? 'Total' }}</td>
            <td class="num">{{ $invoice['gross_total'] ?? '' }} {{ $invoice['currency'] ?? '' }}</td>
        </tr>
    </table>

    @if (!empty($invoice['qr_placeholder']))
        <div class="qr">{{ $labels['qr_placeholder'] ?? 'QR' }}</div>
    @endif
</body>
</html>
