<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Клієнти 1–3 замовлення</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: -apple-system, "Segoe UI", Roboto, Arial, sans-serif; color: #1e293b; margin: 0; padding: 24px; background: #f8fafc; }
        .wrap { max-width: 1000px; margin: 0 auto; }
        h1 { font-size: 22px; margin: 0 0 4px; }
        .sub { color: #64748b; font-size: 13px; margin-bottom: 16px; }
        .summary { display: flex; gap: 10px; margin-bottom: 18px; flex-wrap: wrap; }
        .chip { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 8px 14px; font-size: 13px; }
        .chip b { font-size: 18px; display: block; }
        table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; overflow: hidden; }
        th, td { text-align: left; padding: 10px 14px; border-bottom: 1px solid #eef2f7; font-size: 13px; }
        th { background: #f1f5f9; color: #475569; text-transform: uppercase; font-size: 11px; letter-spacing: .04em; }
        tr:last-child td { border-bottom: none; }
        td.num { text-align: center; width: 46px; color: #94a3b8; }
        .cnt { display: inline-block; min-width: 22px; text-align: center; border-radius: 6px; padding: 2px 8px; font-weight: 700; color: #fff; }
        .cnt-1 { background: #ef4444; }
        .cnt-2 { background: #f59e0b; }
        .cnt-3 { background: #10b981; }
        .phone { font-variant-numeric: tabular-nums; white-space: nowrap; }
        .brand { color: #64748b; }
        @media print { body { background: #fff; padding: 0; } .chip, table { border-color: #cbd5e1; } }
    </style>
</head>
<body>
<div class="wrap">
    @php
        $c1 = $clients->where('count', 1)->count();
        $c2 = $clients->where('count', 2)->count();
        $c3 = $clients->where('count', 3)->count();
    @endphp

    <h1>Клієнти з 1–3 замовленнями</h1>
    <div class="sub">Хто замовляв 1, 2 або 3 рази (4+ не показуємо) — кандидати на дотиск до наступного замовлення.</div>

    <div class="summary">
        <div class="chip">Усього <b>{{ $clients->count() }}</b></div>
        <div class="chip">1 замовлення <b>{{ $c1 }}</b></div>
        <div class="chip">2 замовлення <b>{{ $c2 }}</b></div>
        <div class="chip">3 замовлення <b>{{ $c3 }}</b></div>
    </div>

    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Клієнт</th>
                <th>Телефон</th>
                <th>Тариф</th>
                <th>Бренд</th>
                <th style="text-align:center;">Замовлення №</th>
            </tr>
        </thead>
        <tbody>
            @forelse($clients as $i => $cl)
                <tr>
                    <td class="num">{{ $i + 1 }}</td>
                    <td>{{ $cl['name'] }}</td>
                    <td class="phone">{{ $cl['phone'] }}</td>
                    <td>{{ $cl['tariff'] }}</td>
                    <td class="brand">{{ $cl['brand'] }}</td>
                    <td style="text-align:center;"><span class="cnt cnt-{{ $cl['count'] }}">{{ $cl['count'] }}</span></td>
                </tr>
            @empty
                <tr><td colspan="6" style="text-align:center; color:#94a3b8; padding:24px;">Немає клієнтів у цьому діапазоні</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
</body>
</html>
