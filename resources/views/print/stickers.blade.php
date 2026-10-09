<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <title>Друк стікерів — {{ \Carbon\Carbon::parse($date)->addDay()->format('d.m.Y') }}</title>
    <style>
        * {
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
            color-adjust: exact !important;
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 10px;
            background: #e2e8f0;
        }

        .no-print {
            padding: 20px;
            text-align: center;
        }

        .no-print button {
            background: #334155;
            color: white;
            border: none;
            padding: 16px 40px;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 900;
            cursor: pointer;
            letter-spacing: 2px;
            text-transform: uppercase;
        }

        .no-print button:hover {
            background: #475569;
        }

        /* A4 page with 69x41mm labels, 3 cols x 7 rows = 21 per sheet */
        /* A4: 210×297mm. Відступ: зліва 5mm, зверху 1mm.
           Стікер: 68.33mm × 42mm. 3×7 = 21 шт/лист */
        .label-sheet {
            width: 210mm;
            height: 297mm;
            padding-left: 5mm;
            padding-top: 1mm;
            margin: 10px auto;
            background: white;
            display: grid;
            grid-template-columns: repeat(3, 68.33mm);
            grid-template-rows: repeat(7, 42mm);
            align-content: start;
            justify-content: start;
        }

        .sticker {
            width: 68.33mm;
            height: 42mm;
            max-height: 42mm;
            position: relative;
            overflow: hidden;
            padding: 2mm 2.5mm 1.5mm 2.5mm;
            display: flex;
            flex-direction: column;
            border: none;
            break-inside: avoid;
            page-break-inside: avoid;
        }

        .sticker > div {
            min-height: 0;
            flex-shrink: 1;
        }

        .sticker-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            padding-bottom: 1mm;
            margin-bottom: 1mm;
        }

        .client-name {
            font-size: 9px;
            font-weight: 900;
            text-transform: uppercase;
            color: #0f172a;
            line-height: 1.1;
            max-width: 70%;
            white-space: nowrap;
            overflow: visible;
            display: block;
        }

        .client-bundles {
            font-size: 7px;
            font-weight: 800;
            color: #5b21b6;
            background: #ede9fe;
            border: 0.3mm solid #c4b5fd;
            border-radius: 1mm;
            padding: 0.2mm 1mm;
            display: inline-block;
            margin-top: 0.5mm;
            line-height: 1.1;
            max-width: 70%;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .client-id {
            font-size: 7px;
            font-weight: 700;
            background: #1e293b;
            color: white;
            padding: 1px 4px;
            border-radius: 2px;
            display: inline-block;
            margin-top: 1px;
        }

        .sticker.has-changes .client-id {
            font-size: 12px;
            font-weight: 900;
            padding: 2px 6px;
            margin-top: 1.5px;
            letter-spacing: 0.3px;
        }

        /* Клієнт зі своїм КБЖУ на звичайному меню — кухня має бачити це
           поруч з ID, бо заміни в нього оформлені індивідуально. */
        .ind-tag {
            font-size: 7px;
            font-weight: 900;
            background: #7c3aed;
            color: #fff;
            padding: 1px 4px;
            border-radius: 2px;
            display: inline-block;
            margin-top: 1px;
            margin-left: 2px;
            letter-spacing: 0.3px;
        }

        .sticker.has-changes .ind-tag {
            font-size: 10px;
            padding: 2px 5px;
            margin-top: 1.5px;
        }

        .calories {
            font-size: 10px;
            font-weight: 900;
            background: #f1f5f9;
            color: #1e293b;
            padding: 2px 5px;
            border-radius: 2px;
            white-space: nowrap;
        }

        .meal-row {
            display: flex;
            align-items: center;
            gap: 4px;
            margin-bottom: 1mm;
        }

        .meal-type {
            font-size: 8px;
            font-weight: 900;
            text-transform: uppercase;
        }

        .meal-date {
            font-size: 7px;
            font-weight: 700;
            background: #fde047;
            color: #000;
            padding: 1px 4px;
            border-radius: 2px;
        }

        .meal-slot {
            font-size: 7px;
            font-weight: 900;
            padding: 1px 4px;
            border-radius: 2px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .meal-slot.morning { background: #fde047; color: #000; }
        .meal-slot.evening { background: #1e293b; color: #fff; }

        .dish-name {
            font-size: 10px;
            font-weight: 800;
            color: #0f172a;
            line-height: 1.15;
            overflow: hidden;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
        }

        /* Прийом прибрано навмисно. Синій, а не червоний: для кухні це не
           проблема зі стравою. Пояснень не пишемо — стікер бачить клієнт. */
        .missing-box {
            background: #eff6ff;
            border: 0.5px solid #bfdbfe;
            border-radius: 2px;
            padding: 2px 3px;
            margin-top: 1mm;
        }

        .missing-title {
            font-size: 11px;
            font-weight: 900;
            color: #1d4ed8;
            line-height: 1.15;
        }

        .changes-box {
            background: #fef2f2;
            border: 0.5px solid #fecaca;
            border-radius: 2px;
            padding: 1px 3px;
            margin-top: 1mm;
            overflow: hidden;
            max-height: 12mm;
        }

        .change-item {
            font-size: 7px;
            font-weight: 800;
            color: #dc2626;
            text-transform: uppercase;
            line-height: 1.2;
        }

        .circles-row {
            display: flex;
            gap: 2px;
            margin-top: 1mm;
        }

        .meal-circle {
            width: 13px;
            height: 13px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 6px;
            font-weight: 900;
            color: white;
            flex-shrink: 0;
            line-height: 1;
        }

        .weight-row {
            text-align: right;
            margin-top: auto;
            padding-top: 0.5mm;
            border-top: 0.5px dashed #e5e7eb;
            flex-shrink: 0;
        }

        .weight-value {
            font-size: 13px;
            font-weight: 900;
        }

        .weight-unit {
            font-size: 8px;
            font-weight: 600;
            color: #9ca3af;
            margin-left: 1px;
        }

        .brand-logo {
            height: 9mm;
            width: auto;
            max-width: 16mm;
            object-fit: contain;
            object-position: right center;
            display: block;
            flex-shrink: 0;
        }

        /* Панель над аркушами: фільтр і формат (як у стікерах на пакети). */
        .toolbar { display: flex; align-items: center; justify-content: center; gap: 12px; flex-wrap: wrap; }
        .toolbar .group { display: flex; gap: 6px; background: #1e293b; padding: 6px; border-radius: 12px; }
        .toolbar a.opt {
            color: #cbd5e1; text-decoration: none; font-size: 13px; font-weight: 800;
            padding: 8px 14px; border-radius: 8px; white-space: nowrap;
        }
        .toolbar a.opt.active { background: #f97316; color: #fff; }
        .toolbar .hint { width: 100%; font-size: 12px; color: #475569; margin-top: 8px; }

        /* ===== ВЕЛИКИЙ ФОРМАТ 70×99мм (3×3 = 9/аркуш) — як «На пакет» ===== */
        body.fmt-large .label-sheet {
            grid-template-columns: repeat(3, 70mm);
            grid-template-rows: repeat(3, 99mm);
        }
        body.fmt-large .sticker {
            width: 70mm;
            height: 99mm;
            max-height: 99mm;
            padding: 4mm 4mm 3mm 4mm;
        }
        body.fmt-large .client-name    { font-size: 15px; white-space: normal; max-width: 100%; }
        body.fmt-large .sticker-header > div:first-child { flex: 1; min-width: 0; padding-right: 2mm; }
        body.fmt-large .client-bundles { font-size: 10px; }
        body.fmt-large .client-id,
        body.fmt-large .sticker.has-changes .client-id { font-size: 15px; padding: 3px 8px; }
        body.fmt-large .ind-tag,
        body.fmt-large .sticker.has-changes .ind-tag   { font-size: 13px; padding: 3px 7px; }
        body.fmt-large .calories       { font-size: 14px; padding: 3px 7px; }
        body.fmt-large .meal-type      { font-size: 13px; }
        body.fmt-large .meal-date,
        body.fmt-large .meal-slot      { font-size: 11px; padding: 2px 6px; }
        body.fmt-large .meal-row       { gap: 6px; margin-bottom: 2mm; }
        body.fmt-large .dish-name      { font-size: 15px; -webkit-line-clamp: 3; }
        body.fmt-large .missing-title  { font-size: 16px; }
        body.fmt-large .changes-box    { max-height: none; padding: 2mm 3mm; margin-top: 2mm; }
        body.fmt-large .change-item    { font-size: 12px; line-height: 1.3; }
        body.fmt-large .meal-circle    { width: 20px; height: 20px; font-size: 9px; }
        body.fmt-large .circles-row    { gap: 4px; margin-top: 2mm; }
        body.fmt-large .weight-value   { font-size: 24px; }
        body.fmt-large .weight-unit    { font-size: 12px; }
        body.fmt-large .brand-logo     { height: 12mm; max-width: 22mm; }

        @media print {
            .no-print { display: none !important; }
            body { background: white !important; }

            .label-sheet {
                margin: 0 !important;
                page-break-after: always;
                break-after: page;
            }

            .label-sheet:last-child {
                page-break-after: auto;
            }

            @page {
                size: A4;
                margin: 0;
            }
        }
    </style>
</head>
<body class="{{ $format === 'large' ? 'fmt-large' : '' }}">

@php
    // Посилання зберігають дату й інший перемикач — сторінка перебудовується
    // на сервері, щоб аркуші ділились під формат (21 чи 9) і лічильник був чесним.
    $link = fn (array $change) => route('print.stickers', array_merge(
        ['date' => $date, 'format' => $format, 'no_ind' => $hideIndividual ? 1 : 0],
        $change,
    ));
    $perSheet = $format === 'large' ? 9 : 21;
    $chunks = array_chunk($stickers, $perSheet);
@endphp

<div class="no-print toolbar">
    <div class="group">
        <a class="opt {{ $hideIndividual ? '' : 'active' }}" href="{{ $link(['no_ind' => 0]) }}">Усі клієнти</a>
        <a class="opt {{ $hideIndividual ? 'active' : '' }}" href="{{ $link(['no_ind' => 1]) }}">Без індивідуальних</a>
    </div>
    <div class="group">
        <a class="opt {{ $format === 'small' ? 'active' : '' }}" href="{{ $link(['format' => 'small']) }}" title="68×42 мм — 21 шт/аркуш">▪▪▪ Малий 68×42</a>
        <a class="opt {{ $format === 'large' ? 'active' : '' }}" href="{{ $link(['format' => 'large']) }}" title="70×99 мм — 9 шт/аркуш, як «На пакет»">▬▬ Великий 70×99</a>
    </div>
    <button onclick="window.print()">
        РОЗДРУКУВАТИ ({{ count($stickers) }} шт. · {{ count($chunks) }} арк.)
    </button>
    @if($hideIndividual && $hiddenIndividual)
        <div class="hint">Приховано стікерів індивідуальних клієнтів: {{ $hiddenIndividual }}</div>
    @endif
</div>

@foreach($chunks as $sheetIndex => $sheet)
    <div class="label-sheet">
        @foreach($sheet as $sticker)
            @php
                $project = \App\Models\Project::where('slug', $sticker['project'])->first();
                $brandColor = match($project?->color) {
                    'success' => '#22c55e',
                    'primary' => '#3b82f6',
                    'info'    => '#06b6d4',
                    'warning' => '#eab308',
                    'danger'  => '#ef4444',
                    default   => '#000000',
                };

                // Логотип — точно так само як у маніфестах
                $logoBase64 = ($project?->logo && file_exists(storage_path('app/public/' . $project->logo)))
                    ? 'data:image/png;base64,' . base64_encode(file_get_contents(storage_path('app/public/' . $project->logo)))
                    : null;
            @endphp

            <div class="sticker @if(!empty($sticker['changes'])) has-changes @endif">

                <div>
                    <div class="sticker-header">
                        <div>
                            <div class="client-name">{{ $sticker['client'] }}</div>
                            @if(!empty($sticker['bundles']))
                                <div class="client-bundles">{{ implode(', ', $sticker['bundles']) }}</div>
                            @endif
                            <span class="client-id">ID: {{ $sticker['client_id'] }}</span>@if(!empty($sticker['is_individual']))<span class="ind-tag">ІНД</span>@endif
                        </div>
                        @if($logoBase64)
                            <img src="{{ $logoBase64 }}" class="brand-logo" alt="logo">
                        @endif
                    </div>

                    <div class="meal-row">
                        <span class="meal-type" style="color: {{ $brandColor }};">{{ $sticker['meal'] }}</span>
                        <span class="meal-date">{{ \Carbon\Carbon::parse($date)->addDay()->format('d.m') }}</span>
                        @if(isset($sticker['is_evening']))
                            <span class="meal-slot {{ $sticker['is_evening'] ? 'evening' : 'morning' }}">{{ $sticker['delivery_slot'] }}</span>
                        @endif
                        <span class="calories">{{ $sticker['calories'] }}</span>
                    </div>

                    @if(!empty($sticker['missing_meals']))
                        {{-- Інформаційний стікер: прийом прибрано навмисно, це не збій. --}}
                        <div class="missing-box">
                            <div class="missing-title">НЕМАЄ: {{ mb_strtoupper(implode(', ', $sticker['missing_meals'])) }}</div>
                        </div>
                    @else
                    <div class="dish-name">{{ $sticker['dish'] }}</div>
                    @endif

                    @if(!empty($sticker['changes']))
                        <div class="changes-box">
                            @foreach($sticker['changes'] as $change)
                                <div class="change-item">{{ $change }}</div>
                            @endforeach

                            @if(!empty($sticker['circles']))
                                <div class="circles-row">
                                    @foreach($sticker['circles'] as $circle)
                                        <div class="meal-circle" style="background-color: {{ $circle['color'] }};">{{ $circle['letter'] }}</div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endif
                </div>

                <div class="weight-row">
                    <span class="weight-value" style="color: {{ $brandColor }};">{{ $sticker['weight'] }}</span><span class="weight-unit">г</span>
                </div>

                <div class="brand-watermark">{{ $project?->name ?? 'BRAND' }} DELIVERY</div>
            </div>
        @endforeach
    </div>
@endforeach

<script>
    // Автоматично зменшує шрифт стікера якщо контент не влізає
    document.addEventListener('DOMContentLoaded', function () {
        const PX_PER_MM = 3.7795;
        const maxHeightPx = ({{ $format === 'large' ? 99 : 42 }}) * PX_PER_MM;

        document.querySelectorAll('.sticker > div').forEach(function (inner) {
            // На великому форматі шрифти задає CSS — тут лише ужимаємо, якщо не влізло.
            if (document.body.classList.contains('fmt-large') && inner.scrollHeight <= maxHeightPx) return;
            let fontSize = 10; // початковий розмір в px
            inner.style.fontSize = fontSize + 'px';

            while (inner.scrollHeight > maxHeightPx && fontSize > 6) {
                fontSize -= 0.5;
                inner.style.fontSize = fontSize + 'px';
            }
        });
    });

    // Автозменшення шрифту імені якщо не вміщується
    function fitClientNames() {
        document.querySelectorAll('.client-name').forEach(function(el) {
            el.style.fontSize = '';
            var parent = el.parentElement;
            var maxW = parent ? parent.offsetWidth : 0;
            if (!maxW) return;
            // На великому імʼя переноситься на другий рядок — не зменшуємо.
            if (document.body.classList.contains('fmt-large')) return;
            var sizeNum = 9;
            var minSize = 5;
            while (el.scrollWidth > maxW && sizeNum > minSize) {
                sizeNum -= 0.5;
                el.style.fontSize = sizeNum + 'px';
            }
        });
    }
    window.addEventListener('load', fitClientNames);
</script>
</body>
</html>
