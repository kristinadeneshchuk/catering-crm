<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Клієнти 1–3 замовлення</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: -apple-system, "Segoe UI", Roboto, Arial, sans-serif; color: #1e293b; margin: 0; padding: 24px; background: #f8fafc; }
        .wrap { max-width: 1100px; margin: 0 auto; }
        h1 { font-size: 22px; margin: 0 0 4px; }
        .sub { color: #64748b; font-size: 13px; margin-bottom: 16px; }
        .summary { display: flex; gap: 10px; margin-bottom: 16px; flex-wrap: wrap; }
        .chip { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 8px 14px; font-size: 13px; }
        .chip b { font-size: 18px; display: block; }
        .filters { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 14px; margin-bottom: 16px; display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; }
        .filters .fld { display: flex; flex-direction: column; gap: 4px; }
        .filters label { font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: #64748b; }
        .filters select, .filters input { padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 13px; background: #fff; min-width: 150px; }
        .btn { padding: 8px 16px; border-radius: 8px; border: none; font-size: 13px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn-primary { background: #f59e0b; color: #fff; }
        .btn-reset { background: #f1f5f9; color: #475569; }
        table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; overflow: hidden; }
        th, td { text-align: left; padding: 10px 14px; border-bottom: 1px solid #eef2f7; font-size: 13px; }
        th { background: #f1f5f9; color: #475569; text-transform: uppercase; font-size: 11px; letter-spacing: .04em; }
        tr:last-child td { border-bottom: none; }
        td.id { color: #94a3b8; font-variant-numeric: tabular-nums; }
        .cnt { display: inline-block; min-width: 22px; text-align: center; border-radius: 6px; padding: 2px 8px; font-weight: 700; color: #fff; }
        .cnt-1 { background: #ef4444; } .cnt-2 { background: #f59e0b; } .cnt-3 { background: #10b981; }
        .phone { font-variant-numeric: tabular-nums; white-space: nowrap; }
        .brand { color: #64748b; }
        .date { white-space: nowrap; color: #475569; font-variant-numeric: tabular-nums; }
        @media print { body { background: #fff; padding: 0; } .filters { display: none; } }
        .called-cb { width: 18px; height: 18px; cursor: pointer; accent-color: #10b981; }
        tr.called td { opacity: .45; }
        tr.called td:first-child::after { content: ' ✓'; color: #10b981; font-weight: 700; }
        .called-label { display: flex; align-items: center; justify-content: center; gap: 6px; }
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
    <div class="sub">Хто замовляв 1, 2 або 3 рази (4+ не показуємо). Фільтр по місяцю/даті — за <b>датою закінчення</b> замовлення (тобто «був на замовленні і не продовжив»).</div>

    <form class="filters" method="GET" action="{{ route('print.repeat-clients') }}">
        <div class="fld">
            <label>Тариф</label>
            <select name="tariff_id">
                <option value="">Усі</option>
                @foreach($tariffs as $id => $name)
                    <option value="{{ $id }}" @selected((string)($filters['tariff_id'] ?? '') === (string)$id)>{{ $name }}</option>
                @endforeach
            </select>
        </div>
        <div class="fld">
            <label>Бренд</label>
            <select name="brand">
                <option value="">Усі</option>
                @foreach($brands as $slug => $name)
                    <option value="{{ $slug }}" @selected(($filters['brand'] ?? '') === $slug)>{{ $name }}</option>
                @endforeach
            </select>
        </div>
        <div class="fld">
            <label>Місяць (закінчення)</label>
            <input type="month" name="month" value="{{ $filters['month'] ?? '' }}">
        </div>
        <div class="fld">
            <label>Дата закінч. з</label>
            <input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}">
        </div>
        <div class="fld">
            <label>по</label>
            <input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}">
        </div>
        <button type="submit" class="btn btn-primary">Застосувати</button>
        <a href="{{ route('print.repeat-clients') }}" class="btn btn-reset">Скинути</a>
    </form>

    <div class="summary">
        <div class="chip">Знайдено <b>{{ $clients->count() }}</b></div>
        <div class="chip">1 замовлення <b>{{ $c1 }}</b></div>
        <div class="chip">2 замовлення <b>{{ $c2 }}</b></div>
        <div class="chip">3 замовлення <b>{{ $c3 }}</b></div>
    </div>

    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Клієнт</th>
                <th>Телефон</th>
                <th>Тариф</th>
                <th>Бренд</th>
                <th>Замовлення (період)</th>
                <th style="text-align:center;">№</th>
                <th style="text-align:center;">Дзвонила</th>
            </tr>
        </thead>
        <tbody>
            @forelse($clients as $cl)
                <tr data-key="{{ $cl['id'] }}">
                    <td class="id">{{ $cl['id'] }}</td>
                    <td>{{ $cl['name'] }}</td>
                    <td class="phone">{{ $cl['phone'] }}</td>
                    <td>{{ $cl['tariff'] }}</td>
                    <td class="brand">{{ $cl['brand'] }}</td>
                    <td class="date">{{ $cl['start'] ? \Carbon\Carbon::parse($cl['start'])->format('d.m.y') : '—' }} – {{ $cl['end'] ? \Carbon\Carbon::parse($cl['end'])->format('d.m.y') : '—' }}</td>
                    <td style="text-align:center;"><span class="cnt cnt-{{ $cl['count'] }}">{{ $cl['count'] }}</span></td>
                    <td style="text-align:center;">
                        <label class="called-label">
                            <input type="checkbox" class="called-cb" data-key="{{ $cl['id'] }}">
                        </label>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" style="text-align:center; color:#94a3b8; padding:24px;">Немає клієнтів за цими фільтрами</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<script>
const LS_KEY = 'repeat_clients_called';
function loadCalled() {
    try { return new Set(JSON.parse(localStorage.getItem(LS_KEY) || '[]')); }
    catch { return new Set(); }
}
function saveCalled(set) {
    localStorage.setItem(LS_KEY, JSON.stringify([...set]));
}
const called = loadCalled();
document.querySelectorAll('.called-cb').forEach(cb => {
    const key = cb.dataset.key;
    const row = cb.closest('tr');
    if (called.has(key)) { cb.checked = true; row.classList.add('called'); }
    cb.addEventListener('change', () => {
        if (cb.checked) { called.add(key); row.classList.add('called'); }
        else { called.delete(key); row.classList.remove('called'); }
        saveCalled(called);
    });
});
</script>
</body>
</html>
