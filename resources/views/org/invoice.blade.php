@php
    use App\Domain\Billing\Support\PriceFormatter;

    $sellerLabels = ['name', 'inn', 'address', 'bank', 'bank_account', 'mfo', 'phone', 'email'];
    $payerLabels = ['name', 'email', 'phone', 'telegram'];
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('org.invoice.title', ['number' => $invoice->number]) }}</title>
    <style>
        body { font: 15px/1.5 -apple-system, "Segoe UI", Roboto, Arial, sans-serif; color: #111; max-width: 780px; margin: 32px auto; padding: 0 16px; }
        h1 { font-size: 22px; margin: 0 0 4px; }
        .muted { color: #555; font-size: 13px; }
        .parties { display: flex; gap: 32px; flex-wrap: wrap; margin: 24px 0; }
        .parties > div { flex: 1 1 300px; }
        h2 { font-size: 13px; text-transform: uppercase; letter-spacing: .05em; color: #555; margin: 0 0 6px; }
        dl { margin: 0; }
        dt { display: inline; color: #555; }
        dd { display: inline; margin: 0; }
        dd::after { content: ""; display: block; }
        table { width: 100%; border-collapse: collapse; margin: 16px 0; }
        th, td { text-align: left; padding: 8px; border-bottom: 1px solid #ccc; }
        th.num, td.num { text-align: right; white-space: nowrap; }
        tfoot td { font-weight: 700; border-bottom: 0; }
        .status { display: inline-block; padding: 2px 8px; border: 1px solid #999; border-radius: 4px; font-size: 13px; }
        @media print { body { margin: 0; } .noprint { display: none; } }
    </style>
</head>
<body>
    <h1>{{ __('org.invoice.heading', ['number' => $invoice->number]) }}</h1>
    <p class="muted">
        {{ __('org.invoice.date') }}: {{ $invoice->issuedAt->format('d.m.Y') }}
        · <span class="status">{{ __('org.invoice.status.'.$invoice->status) }}</span>
    </p>

    <div class="parties">
        <div>
            <h2>{{ __('org.invoice.seller') }}</h2>
            @if ($invoice->seller === [])
                <p class="muted">{{ __('org.invoice.seller_empty') }}</p>
            @else
                <dl>
                    @foreach ($sellerLabels as $key)
                        @if (isset($invoice->seller[$key]))
                            <dt>{{ __('org.invoice.seller_fields.'.$key) }}:</dt> <dd>{{ $invoice->seller[$key] }}</dd>
                        @endif
                    @endforeach
                </dl>
            @endif
        </div>
        <div>
            <h2>{{ __('org.invoice.payer') }}</h2>
            <dl>
                @foreach ($payerLabels as $key)
                    @if (isset($invoice->payer[$key]))
                        <dt>{{ __('org.invoice.payer_fields.'.$key) }}:</dt> <dd>{{ $invoice->payer[$key] }}</dd>
                    @endif
                @endforeach
            </dl>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>{{ __('org.invoice.item') }}</th>
                <th class="num">{{ __('org.invoice.sum') }}</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>
                    {{ __('org.invoice.service', ['tier' => __('org.billing.tiers.'.$invoice->tier.'.name')]) }}<br>
                    <span class="muted">
                        {{ $invoice->seats === null ? __('org.billing.seats_unlimited') : __('org.billing.seats_count', ['count' => $invoice->seats]) }}
                        · {{ __('org.invoice.period', ['days' => $invoice->periodDays]) }}
                    </span>
                </td>
                <td class="num">{{ PriceFormatter::format($invoice->amount, $invoice->currency, $locale) }}</td>
            </tr>
        </tbody>
        <tfoot>
            <tr>
                <td>{{ __('org.invoice.total') }}</td>
                <td class="num">{{ PriceFormatter::format($invoice->amount, $invoice->currency, $locale) }}</td>
            </tr>
        </tfoot>
    </table>

    <p class="muted">{{ __('org.invoice.note') }}</p>
    <p class="muted noprint">{{ __('org.invoice.print_hint') }}</p>
</body>
</html>
