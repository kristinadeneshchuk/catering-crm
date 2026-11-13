<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <title>Наліпки на обіди — {{ \Carbon\Carbon::parse($day)->format('d.m.Y') }}</title>
    <style>
        * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 10px; background: #e2e8f0; color: #0f172a; }

        .toolbar { padding: 16px 20px; text-align: center; }
        .toolbar a, .toolbar button { display: inline-block; margin: 4px; padding: 10px 18px; border-radius: 10px; border: 1px solid #94a3b8;
            background: #fff; color: #0f172a; font-size: 13px; font-weight: 700; text-decoration: none; cursor: pointer; }
        .toolbar a.on { background: #0f172a; color: #fff; border-color: #0f172a; }
        .toolbar button { background: #334155; color: #fff; border: none; padding: 12px 32px; font-size: 15px; letter-spacing: 1px; text-transform: uppercase; }
        .toolbar .hint { font-size: 12px; color: #475569; margin-top: 8px; }

        /* Малий папір 68,33×42 мм, 3×7 = 21 на A4 — той самий, що для наліпок раціонів. */
        .label-sheet { width: 210mm; height: 297mm; padding-left: 5mm; padding-top: 1mm; margin: 10px auto; background: #fff;
            display: grid; grid-template-columns: repeat(3, 68.33mm); grid-template-rows: repeat(7, 42mm); align-content: start; justify-content: start; }
        .sticker { width: 68.33mm; height: 42mm; overflow: hidden; padding: 2mm 2.5mm 1.5mm; display: flex; flex-direction: column; break-inside: avoid; }

        /* Великий 70×99 мм, 3×3 = 9 на A4. */
        body.fmt-large .label-sheet { padding: 0; grid-template-columns: repeat(3, 70mm); grid-template-rows: repeat(3, 99mm); }
        body.fmt-large .sticker, body.fmt-large .sticker.bag { width: 70mm; height: 99mm; padding: 5mm; }

        .top { display: flex; justify-content: space-between; align-items: flex-start; gap: 1.5mm; }
        .company { font-size: 8px; font-weight: 800; text-transform: uppercase; letter-spacing: .2px; line-height: 1.1; }
        .kit { flex: none; font-size: 11px; font-weight: 900; background: #0f172a; color: #fff; border-radius: 1.2mm; padding: .6mm 1.6mm; white-space: nowrap; }
        .person { font-size: 13px; font-weight: 900; line-height: 1.05; margin-top: .8mm; }
        .dish { font-size: 10px; font-weight: 700; line-height: 1.15; margin-top: 1mm; flex: 1; overflow: hidden; }
        .bottom { display: flex; justify-content: space-between; align-items: flex-end; font-size: 8px; font-weight: 700; }
        .kind { border: .3mm solid #0f172a; border-radius: 1mm; padding: .2mm 1.2mm; }
        .weight { font-size: 11px; font-weight: 900; }

        /* Наліпка на пакет — увесь комплект людини. */
        /* Рамка замість суцільної заливки: видно здалеку і не зʼїдає тонер. */
        .sticker.bag { box-shadow: inset 0 0 0 1.1mm #0f172a; padding: 2.6mm 3.2mm 2.2mm; }
        .sticker.bag .bagmark { background: #0f172a; color: #fff; border-radius: 1mm; padding: .3mm 1.4mm; font-weight: 900; }
        .sticker.bag .items { margin-top: 1mm; flex: 1; overflow: hidden; font-size: 8.5px; font-weight: 700; line-height: 1.2; }
        .sticker.bag .items div { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        body.fmt-large .company { font-size: 13px; }
        body.fmt-large .kit { font-size: 20px; padding: 1.5mm 3mm; }
        body.fmt-large .person { font-size: 24px; margin-top: 3mm; }
        body.fmt-large .dish { font-size: 18px; margin-top: 4mm; }
        body.fmt-large .bottom { font-size: 13px; }
        body.fmt-large .weight { font-size: 20px; }
        body.fmt-large .sticker.bag .items { font-size: 14px; margin-top: 3mm; }
        body.fmt-large .sticker.bag .items div { white-space: normal; }

        @media print {
            .no-print { display: none !important; }
            body { background: #fff !important; }
            .label-sheet { margin: 0 !important; page-break-after: always; break-after: page; }
            .label-sheet:last-child { page-break-after: auto; }
            @page { size: A4; margin: 0; }
        }
    </style>
</head>
<body class="fmt-{{ $format }}">
@php
    $kinds = \App\Services\Kitchen\LunchAssembly::KINDS;
    $dm    = \Carbon\Carbon::parse($day)->format('d.m');
    $url   = fn (array $over) => route('print.lunch-stickers', array_merge(['day' => $day, 'format' => $format, 'bag' => $bag ? 1 : 0], $over));
    $boxes = collect($stickers)->where('type', 'box')->count();
    $bags  = collect($stickers)->where('type', 'bag')->count();
@endphp

<div class="toolbar no-print">
    <div>
        <a href="{{ $url(['format' => 'small']) }}" class="{{ $format === 'small' ? 'on' : '' }}">Малі 68×42 (21 на аркуші)</a>
        <a href="{{ $url(['format' => 'large']) }}" class="{{ $format === 'large' ? 'on' : '' }}">Великі 70×99 (9 на аркуші)</a>
        <a href="{{ $url(['bag' => $bag ? 0 : 1]) }}" class="{{ $bag ? 'on' : '' }}">Наліпка на пакет: {{ $bag ? 'так' : 'ні' }}</a>
        <a href="{{ route('print.lunch-assembly', ['day' => $day]) }}">Зборочний аркуш</a>
    </div>
    @if (count($stickers))
        <button onclick="window.print()">Друкувати</button>
        <div class="hint">
            Обіди на {{ \Carbon\Carbon::parse($day)->format('d.m.Y') }}: {{ $boxes }} боксів{{ $bags ? ', '.$bags.' пакетів' : '' }}.
            Наліпки однієї людини йдуть підряд: у рамці — на пакет, далі бокси «1 з 2», «2 з 2». У друку: масштаб 100%, без полів.
        </div>
    @else
        <div class="hint" style="font-size:15px">На {{ \Carbon\Carbon::parse($day)->format('d.m.Y') }} обідів із Lunch Hub немає.</div>
    @endif
</div>

@foreach (collect($stickers)->chunk($perSheet) as $sheet)
    <div class="label-sheet">
        @foreach ($sheet as $s)
            @if ($s['type'] === 'bag')
                <div class="sticker bag">
                    <div class="top">
                        <div class="company">{{ $s['company'] }}</div>
                        <div class="kit">{{ $s['of'] }} {{ $s['of'] === 1 ? 'бокс' : ($s['of'] < 5 ? 'бокси' : 'боксів') }}</div>
                    </div>
                    <div class="person">{{ $s['person'] }}</div>
                    <div class="items">
                        @foreach ($s['items'] as $i => $item)
                            <div>{{ $i + 1 }}. {{ $item['name'] }}</div>
                        @endforeach
                    </div>
                    <div class="bottom">
                        <span><span class="bagmark">ПАКЕТ</span> {{ $dm }}</span>
                        <span>{{ $s['window'] }}</span>
                    </div>
                </div>
            @else
                <div class="sticker">
                    <div class="top">
                        <div class="company">{{ $s['company'] }}</div>
                        @if ($s['n'])<div class="kit">{{ $s['n'] }} з {{ $s['of'] }}</div>@endif
                    </div>
                    @if ($s['person'])<div class="person">{{ $s['person'] }}</div>@endif
                    <div class="dish">{{ $s['dish'] }}</div>
                    <div class="bottom">
                        <span>@if ($s['kind'])<span class="kind">{{ $kinds[$s['kind']] ?? $s['kind'] }}</span>@endif {{ $dm }}</span>
                        @if ($s['weight_g'])<span class="weight">{{ $s['weight_g'] }} г</span>@endif
                    </div>
                </div>
            @endif
        @endforeach
    </div>
@endforeach
</body>
</html>
