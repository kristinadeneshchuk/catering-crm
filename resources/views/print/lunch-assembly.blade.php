<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <title>Зборочний обідів — {{ \Carbon\Carbon::parse($day)->format('d.m.Y') }}</title>
    <style>
        * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 12px; color: #0f172a; margin: 0; padding: 16px; background: #f1f5f9; }
        .page { max-width: 190mm; margin: 0 auto; background: #fff; padding: 8mm; }
        .toolbar { text-align: center; margin-bottom: 12px; }
        .toolbar a, .toolbar button { display: inline-block; margin: 4px; padding: 10px 18px; border-radius: 10px; border: 1px solid #94a3b8; background: #fff;
            color: #0f172a; font-size: 13px; font-weight: 700; text-decoration: none; cursor: pointer; }
        .toolbar button { background: #334155; color: #fff; border: none; }
        h1 { font-size: 18px; margin: 0 0 2mm; }
        .sub { color: #475569; margin-bottom: 5mm; }
        .company { border: .5mm solid #0f172a; border-radius: 2mm; margin-bottom: 6mm; break-inside: avoid-page; }
        .company-head { background: #0f172a; color: #fff; padding: 2.5mm 3mm; display: flex; justify-content: space-between; gap: 4mm; }
        .company-head .name { font-size: 16px; font-weight: 900; }
        .company-head .meta { font-size: 11px; line-height: 1.35; text-align: right; }
        .totals { padding: 2mm 3mm; background: #f1f5f9; border-bottom: .3mm solid #cbd5e1; font-size: 11px; line-height: 1.5; }
        .totals b { font-size: 12px; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: .4px; color: #475569; padding: 1.5mm 3mm; border-bottom: .3mm solid #cbd5e1; }
        td { padding: 2mm 3mm; border-bottom: .2mm solid #e2e8f0; vertical-align: top; }
        tr { break-inside: avoid; }
        td.n { width: 9mm; color: #64748b; }
        td.person { width: 48mm; font-weight: 800; font-size: 13px; }
        td.kit { width: 16mm; text-align: center; font-weight: 900; font-size: 14px; }
        td.check { width: 14mm; }
        .box { display: inline-block; width: 6mm; height: 6mm; border: .5mm solid #0f172a; border-radius: 1mm; }
        .item { display: flex; justify-content: space-between; gap: 3mm; line-height: 1.35; }
        .item .w { white-space: nowrap; font-weight: 700; }
        .kind { display: inline-block; min-width: 4.5mm; text-align: center; font-size: 9px; font-weight: 900; border: .3mm solid #0f172a; border-radius: 1mm; margin-right: 1.5mm; }
        .warn { padding: 2mm 3mm; color: #b91c1c; font-weight: 700; }
        .comment { padding: 2mm 3mm; background: #fef9c3; border-bottom: .3mm solid #cbd5e1; font-weight: 700; }
        @media print { .no-print { display: none !important; } body { background: #fff; padding: 0; } .page { padding: 0; max-width: none; } @page { size: A4; margin: 10mm; } }
    </style>
</head>
<body>
@php $letters = ['main' => 'О', 'light' => 'Л', 'extra' => 'Д']; @endphp
<div class="toolbar no-print">
    <button onclick="window.print()">Друкувати</button>
    <a href="{{ route('print.lunch-stickers', ['day' => $day]) }}">Наліпки на бокси</a>
</div>
<div class="page">
    <h1>Зборочний: корпоративні обіди на {{ \Carbon\Carbon::parse($day)->format('d.m.Y') }}</h1>
    <div class="sub">
        Комплект — усі бокси однієї людини. Зібрали комплект, наклеїли наліпки — поставте позначку.
        О — основна, Л — легка, Д — додаткова.
    </div>

    @forelse ($companies as $c)
        <div class="company">
            <div class="company-head">
                <div>
                    <div class="name">{{ $c['company'] }}</div>
                    <div style="font-size:12px;margin-top:1mm">{{ $c['sets'] }} {{ $c['sets'] === 1 ? 'комплект' : ($c['sets'] < 5 ? 'комплекти' : 'комплектів') }}
                        · {{ collect($c['totals'])->sum('qty') }} боксів</div>
                </div>
                <div class="meta">
                    @if ($c['window'])<div><b>Доставка {{ $c['window'] }}</b></div>@endif
                    @if ($c['address'])<div>{{ $c['address'] }}</div>@endif
                    @if ($c['contact'])<div>{{ $c['contact'] }}</div>@endif
                </div>
            </div>
            @if ($c['comment'])<div class="comment">{{ $c['comment'] }}</div>@endif
            <div class="totals">
                <b>Разом по стравах:</b>
                @foreach ($c['totals'] as $t)
                    <div class="item"><span><span class="kind">{{ $letters[$t['kind']] ?? '·' }}</span>{{ $t['name'] }}@if ($t['weight_g']) · {{ $t['weight_g'] }} г@endif</span><span class="w">× {{ $t['qty'] }}</span></div>
                @endforeach
            </div>

            @if ($c['has_people'])
                <table>
                    <thead><tr><th>№</th><th>Кому</th><th>Що в комплекті</th><th style="text-align:center">Боксів</th><th>Зібрано</th></tr></thead>
                    <tbody>
                        @foreach ($c['people'] as $i => $p)
                            <tr>
                                <td class="n">{{ $i + 1 }}</td>
                                <td class="person">{{ $p['name'] }}</td>
                                <td>
                                    @foreach ($p['items'] as $item)
                                        <div class="item"><span><span class="kind">{{ $letters[$item['kind']] ?? '·' }}</span>{{ $item['name'] }}</span>
                                            <span class="w">@if ($item['weight_g']){{ $item['weight_g'] }} г@endif</span></div>
                                    @endforeach
                                </td>
                                <td class="kit">{{ count($p['items']) }}</td>
                                <td class="check"><span class="box"></span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <div class="warn">Розбивки по людях від Lunch Hub немає — збирайте за загальною кількістю страв.</div>
            @endif
        </div>
    @empty
        <div style="font-size:15px;padding:10mm 0">На цей день обідів із Lunch Hub немає.</div>
    @endforelse
</div>
</body>
</html>
