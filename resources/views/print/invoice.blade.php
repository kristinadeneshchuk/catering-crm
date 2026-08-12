<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <title>Рахунок №{{ $invoice->number }}</title>
    <style>
        /* DejaVu Sans — єдиний шрифт у комплекті dompdf з кирилицею. */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 11px;
            color: #111;
            padding: 30px 34px;
        }
        .brand { font-size: 18px; font-weight: bold; margin-bottom: 2px; }
        .muted { color: #666; }
        h1 { font-size: 15px; margin: 18px 0 12px; }

        table { width: 100%; border-collapse: collapse; }
        .req td { padding: 3px 0; vertical-align: top; }
        .req td:first-child { width: 150px; color: #666; }

        .items { margin-top: 14px; }
        .items th, .items td { border: 1px solid #bbb; padding: 6px 8px; text-align: left; }
        .items th { background: #f2f2f2; font-size: 10px; text-transform: uppercase; letter-spacing: .03em; }
        .items td.num, .items th.num { text-align: right; white-space: nowrap; }

        .total { margin-top: 10px; text-align: right; font-size: 13px; font-weight: bold; }
        .purpose { margin-top: 16px; padding: 8px 10px; background: #f7f7f7; border-left: 3px solid #bbb; }
        .sign { margin-top: 42px; }
        .sign .line { border-bottom: 1px solid #333; width: 210px; height: 26px; }
        .footer { margin-top: 26px; font-size: 9px; color: #888; }
    </style>
</head>
<body>

<div class="brand">{{ $requisites['recipient_name'] ?? $invoice->project }}</div>
<div class="muted">
    @if(!empty($requisites['tax_id'])) ЄДРПОУ/ІПН: {{ $requisites['tax_id'] }} @endif
</div>

<h1>Рахунок на оплату №{{ $invoice->number }} від {{ $invoice->issued_on->format('d.m.Y') }}</h1>

<table class="req">
    <tr><td>Отримувач</td><td><strong>{{ $requisites['recipient_name'] ?? '—' }}</strong></td></tr>
    <tr><td>IBAN</td><td><strong>{{ $requisites['iban'] ?? '—' }}</strong></td></tr>
    @if(!empty($requisites['bank_name']))
        <tr><td>Банк</td><td>{{ $requisites['bank_name'] }}</td></tr>
    @endif
    @if(!empty($requisites['mfo']))
        <tr><td>МФО</td><td>{{ $requisites['mfo'] }}</td></tr>
    @endif
    <tr><td>Платник</td><td>{{ $invoice->client?->name ?? '—' }}</td></tr>
</table>

<table class="items">
    <thead>
        <tr>
            <th style="width:26px;">№</th>
            <th>Найменування</th>
            <th class="num" style="width:52px;">К-сть</th>
            <th class="num" style="width:88px;">Ціна, грн</th>
            <th class="num" style="width:98px;">Сума, грн</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td>1</td>
            <td>
                Послуги з доставки здорового харчування
                @if($order)
                    <br><span class="muted">
                        {{ $order->calories }} ккал
                        @if($order->tariff), тариф «{{ $order->tariff->name }}»@endif
                        @if($order->start_date && $order->end_date)
                            , {{ \Carbon\Carbon::parse($order->start_date)->format('d.m.Y') }}
                            — {{ \Carbon\Carbon::parse($order->end_date)->format('d.m.Y') }}
                        @endif
                    </span>
                @endif
            </td>
            <td class="num">{{ $order?->duration ?? 1 }}</td>
            <td class="num">{{ number_format($pricePerDay, 2, '.', ' ') }}</td>
            <td class="num">{{ number_format($subtotal, 2, '.', ' ') }}</td>
        </tr>
        @if($discount > 0)
            <tr>
                <td>2</td>
                <td>Знижка</td>
                <td class="num">—</td>
                <td class="num">—</td>
                <td class="num">−{{ number_format($discount, 2, '.', ' ') }}</td>
            </tr>
        @endif
    </tbody>
</table>

<div class="total">До сплати: {{ number_format((float) $invoice->amount, 2, '.', ' ') }} грн</div>

<div class="purpose">
    <strong>Призначення платежу:</strong><br>
    {{ $invoice->purpose }}
</div>

<div class="sign">
    <div class="line"></div>
    <div class="muted" style="margin-top:4px;">Підпис / М.П.</div>
</div>

<div class="footer">
    Рахунок сформовано автоматично {{ $invoice->created_at?->format('d.m.Y H:i') }}.
    Оплата цього рахунку означає згоду з умовами надання послуг.
</div>

</body>
</html>
