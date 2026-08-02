{{-- Snapshot-only payload — never Eloquent models. --}}
@php
    /** @var array $document */
    $blocks = $document['blocks'] ?? [];
    $labels = $document['labels'] ?? [];
@endphp
<!DOCTYPE html>
<html lang="{{ $document['locale'] ?? 'en' }}">
<head>
    <meta charset="utf-8">
    <title>{{ $document['title'] ?? 'Contract' }}</title>
    <style>
        @page { margin: 24mm 18mm 28mm 18mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111; line-height: 1.45; }
        h1 { font-size: 18px; margin: 0 0 12px; }
        h2 { font-size: 14px; margin: 16px 0 8px; }
        h3 { font-size: 12px; margin: 12px 0 6px; }
        .divider { border-top: 1px solid #ccc; margin: 12px 0; }
        .parties { width: 100%; margin: 12px 0 18px; }
        .parties td { width: 50%; vertical-align: top; padding-right: 12px; }
        .box { border: 1px solid #ccc; padding: 10px; min-height: 70px; }
        .label { font-size: 9px; text-transform: uppercase; color: #555; margin-bottom: 4px; }
        .flag { color: #b45309; font-size: 10px; margin-top: 6px; }
        table.terms { width: 100%; border-collapse: collapse; margin: 10px 0 16px; }
        table.terms th, table.terms td { border-bottom: 1px solid #ddd; padding: 6px 4px; text-align: left; }
        table.terms th.num, table.terms td.num { text-align: right; }
        .meta-row { margin: 4px 0; }
        .signature { margin-top: 28px; padding: 16px; border: 1px dashed #999; }
        .signature-token { font-family: DejaVu Sans Mono, monospace; font-size: 12px; }
        .page-break { page-break-after: always; }
        .footer {
            position: fixed;
            bottom: -18mm;
            left: 0;
            right: 0;
            height: 14mm;
            font-size: 9px;
            color: #666;
            text-align: center;
        }
        .page-number:after { content: counter(page); }
    </style>
</head>
<body>
    <div class="footer">
        {{ $labels['page'] ?? 'Page' }} <span class="page-number"></span>
    </div>

    <h1>{{ $document['title'] ?? '' }}</h1>

    @foreach ($blocks as $block)
        @php $type = $block['type'] ?? ''; @endphp

        @if ($type === 'heading')
            @if (($block['level'] ?? 1) === 1)
                <h2>{{ $block['text'] ?? '' }}</h2>
            @else
                <h3>{{ $block['text'] ?? '' }}</h3>
            @endif
        @elseif ($type === 'paragraph')
            <div>{!! $block['html'] ?? '' !!}</div>
        @elseif ($type === 'divider')
            <div class="divider"></div>
        @elseif ($type === 'spacer')
            <div style="height: {{ (int) ($block['height'] ?? 24) }}px;"></div>
        @elseif ($type === 'legal_section')
            <h2>{{ $block['number'] ?? '' }}. {{ $block['heading'] ?? '' }}</h2>
            <div style="white-space: pre-wrap;">{{ $block['body'] ?? '' }}</div>
        @elseif ($type === 'parties')
            @php
                $issuer = $block['issuer'] ?? [];
                $tenant = $block['tenant'] ?? [];
                $partyLabels = $block['labels'] ?? [];
                $issuerAddress = $issuer['address'] ?? [];
                $tenantAddress = is_array($tenant['address'] ?? null) ? $tenant['address'] : [];
            @endphp
            <table class="parties">
                <tr>
                    <td>
                        <div class="label">{{ $partyLabels['issuer'] ?? 'Issuer' }}</div>
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
                        <div class="label">{{ $partyLabels['tenant'] ?? 'Tenant' }}</div>
                        <div class="box">
                            <div><strong>{{ $tenant['name'] ?? '' }}</strong></div>
                            <div>{{ $tenant['tax_id'] ?? '' }}</div>
                            <div>{{ $tenantAddress['line1'] ?? '' }}</div>
                            @if (!empty($tenantAddress['line2']))
                                <div>{{ $tenantAddress['line2'] }}</div>
                            @endif
                            <div>{{ trim(($tenantAddress['postal'] ?? '').' '.($tenantAddress['city'] ?? '')) }}</div>
                            <div>{{ $tenantAddress['country'] ?? '' }}</div>
                            @if (!empty($tenant['incomplete']))
                                <div class="flag">{{ $partyLabels['incomplete'] ?? 'Identity incomplete' }}</div>
                            @endif
                        </div>
                    </td>
                </tr>
            </table>
        @elseif ($type === 'terms_table')
            @php
                $terms = $block['terms'] ?? [];
                $termLabels = $block['labels'] ?? [];
                $items = $terms['items'] ?? [];
            @endphp
            <table class="terms">
                <thead>
                    <tr>
                        <th>{{ $termLabels['unit'] ?? 'Item' }}</th>
                        <th class="num">{{ $termLabels['amount'] ?? 'Amount' }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($items as $row)
                        <tr>
                            <td>{{ $row['label'] ?? '' }}</td>
                            <td class="num">{{ $row['amount'] ?? '' }} {{ $row['currency'] ?? '' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="meta-row"><strong>{{ $termLabels['deposit'] ?? 'Deposit' }}:</strong> {{ $terms['deposit'] ?? '' }} {{ $terms['currency'] ?? '' }}</div>
            <div class="meta-row"><strong>{{ $termLabels['move_in'] ?? 'Move-in' }}:</strong> {{ $terms['move_in'] ?? '' }}</div>
            <div class="meta-row"><strong>{{ $termLabels['notice_period'] ?? 'Notice' }}:</strong> {{ $terms['notice_period_days'] ?? '' }} {{ $termLabels['days'] ?? 'days' }}</div>
            <div class="meta-row"><strong>{{ $termLabels['cadence'] ?? 'Cadence' }}:</strong> {{ $terms['cadence'] ?? '' }}</div>
        @elseif ($type === 'signature_anchor')
            <div class="signature">
                <div class="label">{{ $block['label'] ?? 'Signature' }}</div>
                <div class="signature-token">{{ $block['token'] ?? '' }}</div>
            </div>
        @elseif ($type === 'page_break')
            <div class="page-break"></div>
        @endif
    @endforeach
</body>
</html>
