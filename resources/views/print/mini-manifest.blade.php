<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <title>На пакет — {{ \Carbon\Carbon::parse($date)->addDay()->format('d.m.Y') }}</title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
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

        .no-print button:hover { background: #475569; }

        /* A4: 210×297mm. Відступ: зліва 5mm, зверху 1mm.
           Доступна ширина: 205mm. Стікер: 68.33mm × 42mm. 3×7 = 21 шт/лист */
        .label-sheet {
            width: 210mm;
            height: 297mm;
            padding-left: 5mm;
            padding-top: 1mm;
            margin: 0 auto;
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
            position: relative;
            overflow: visible;
            padding: 0;
            display: flex;
            flex-direction: column;
            border: none;
            break-inside: avoid;
            page-break-inside: avoid;
        }

        /* Перевернутий блок маршруту зверху */
        .sticker-route {
            display: none;
        }
        .sticker-route-inner {
            display: flex;
            align-items: center;
            gap: 3px;
            font-size: 6.5pt;
            font-weight: 900;
            color: #1e293b;
            white-space: nowrap;
            overflow: hidden;
        }
        .route-badge {
            background: #0f172a;
            color: #fde047;
            font-size: 7pt;
            font-weight: 900;
            padding: 1px 5px;
            border-radius: 3px;
            letter-spacing: 0.5px;
            flex-shrink: 0;
        }
        .route-address {
            color: #475569;
            font-size: 5.5pt;
            font-weight: 600;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .route-driver {
            color: #1e293b;
            font-size: 7pt;
            font-weight: 800;
            flex-shrink: 0;
        }

        /* Основний контент */
        .sticker-body {
            flex: 1;
            padding: 1.5mm 2mm 1mm 2mm;
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
            gap: 1mm;
            min-height: 0;
            overflow: hidden;
        }

        .sticker-border {
            display: none;
        }

        /* Верх: дата + лого */
        .sticker-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-shrink: 0;
        }

        .date-badge {
            background: #fde047;
            color: #000;
            font-size: 7pt;
            font-weight: 900;
            padding: 1px 5px;
            border-radius: 3px;
            line-height: 1.3;
        }

        .project-logo {
            height: 7mm;
            max-width: 16mm;
            object-fit: contain;
            margin-right: 2mm;
            margin-top: 1mm;
        }

        /* Клієнт */
        .sticker-client {
            padding-bottom: 0.5mm;
            flex-shrink: 1;
            min-height: 0;
            overflow: hidden;
        }

        .label-receiver {
            font-size: 5pt;
            color: #9ca3af;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            display: block;
            margin-bottom: 0.3mm;
        }

        .client-name {
            font-size: 11pt;
            font-weight: 900;
            text-transform: uppercase;
            line-height: 1;
            letter-spacing: -0.3px;
            color: #000;
            white-space: nowrap;
            overflow: visible;
            display: block;
        }

        /* Теги: ID, ккал, адреса */
        .sticker-tags {
            display: flex;
            align-items: center;
            gap: 1.5mm;
            margin-top: 0.5mm;
            flex-wrap: wrap;
            overflow: visible;
        }

        .tag-id {
            background: #0f172a;
            color: #fff;
            font-size: 7pt;
            font-weight: 900;
            padding: 1px 4px;
            border-radius: 3px;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .tag-kcal {
            color: #fff;
            font-size: 7pt;
            font-weight: 900;
            padding: 1px 5px;
            border-radius: 3px;
            white-space: nowrap;
            flex-shrink: 0;
            background: var(--brand-color, #000);
        }

        .tag-slot {
            font-size: 6pt;
            font-weight: 900;
            padding: 1px 4px;
            border-radius: 3px;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .tag-slot.morning { background: #fde047; color: #000; }
        .tag-slot.evening { background: #1e293b; color: #94a3b8; }

        .tag-route {
            font-size: 6pt;
            font-weight: 900;
            padding: 1px 4px;
            border-radius: 3px;
            white-space: nowrap;
            flex-shrink: 0;
            background: #e0f2fe;
            color: #0369a1;
            letter-spacing: 0.2px;
        }

        .tag-individual {
            font-size: 6pt;
            font-weight: 900;
            padding: 1px 5px;
            border-radius: 3px;
            white-space: nowrap;
            flex-shrink: 0;
            background: #7c3aed;
            color: #fff;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        .tag-bundle {
            font-size: 6pt;
            font-weight: 800;
            padding: 1px 5px;
            border-radius: 3px;
            white-space: nowrap;
            flex-shrink: 0;
            background: #ede9fe;
            color: #5b21b6;
            border: 0.3pt solid #c4b5fd;
            letter-spacing: 0.2px;
        }

        .tag-address {
            font-size: 5.5pt;
            color: #6b7280;
            font-style: italic;
            font-weight: 600;
            white-space: normal;
            word-break: break-word;
            line-height: 1.2;
            margin-top: 0.5mm;
            display: block;
        }

        /* Підпис */
        .sticker-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
            margin-top: auto;
        }

        .qr-placeholder {
            margin-right: 2mm;
            margin-bottom: 1.5mm;
            flex-shrink: 0;
        }

        /* QRCode.js creates both canvas + img — hide img, show only canvas */
        .qr-placeholder img { display: none !important; }
        .qr-placeholder canvas {
            display: block !important;
            width: 50px !important;
            height: 50px !important;
        }
        /* After beforeprint swap canvas→img, show the img */
        .qr-placeholder.print-ready img {
            display: block !important;
            width: 50px !important;
            height: 50px !important;
        }
        .qr-placeholder.print-ready canvas { display: none !important; }

        .footer-text {
            font-size: 5pt;
            font-weight: 900;
            color: #cbd5e1;
            text-transform: uppercase;
            letter-spacing: 1.5px;
        }

        .circles-row {
            display: flex;
            gap: 2px;
            margin-top: 1mm;
            flex-wrap: wrap;
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
            color: #fff;
            flex-shrink: 0;
        }

        .no-icons-row {
            display: flex;
            gap: 3px;
            margin-top: 1.5mm;
            align-items: center;
        }

        .no-icon-badge {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 18px;
            height: 18px;
            border-radius: 4px;
            background: #fff1f2;
            border: 1px solid #fecdd3;
            flex-shrink: 0;
        }

        /* ===== ВЕЛИКИЙ ФОРМАТ 70×99мм (3×3 = 9/аркуш) ===== */
        body.fmt-large .label-sheet {
            grid-template-columns: repeat(3, 70mm);
            grid-template-rows: repeat(3, 99mm);
        }
        body.fmt-large .sticker {
            width: 70mm;
            height: 99mm;
            padding: 0;
        }
        body.fmt-large .sticker-route  { display: flex; height: 24mm; padding: 3mm 4mm 2mm 4mm; align-items: center; justify-content: center; transform: rotate(180deg); border-bottom: 1px dashed #cbd5e1; flex-shrink: 0; }
        body.fmt-large .sticker-route-inner { font-size: 11pt; gap: 6px; }
        body.fmt-large .route-badge    { font-size: 16pt; padding: 3px 10px; border-radius: 5px; }
        body.fmt-large .route-driver   { font-size: 12pt; }
        body.fmt-large .sticker-body   { padding: 2mm 3mm 1.5mm 3mm; }
        body.fmt-large .date-badge       { font-size: 9pt; padding: 2px 6px; }
        body.fmt-large .project-logo     { height: 10mm; max-width: 22mm; }
        body.fmt-large .label-receiver   { font-size: 7pt; margin-bottom: 1mm; }
        body.fmt-large .client-name      { font-size: 18pt; }
        body.fmt-large .tag-id           { font-size: 9pt; padding: 2px 6px; }
        body.fmt-large .tag-kcal         { font-size: 9pt; padding: 2px 6px; }
        body.fmt-large .tag-slot         { font-size: 8pt; padding: 2px 5px; }
        body.fmt-large .tag-route        { font-size: 8pt; padding: 2px 5px; }
        body.fmt-large .tag-address      { font-size: 8pt; margin-top: 1.5mm; }
        body.fmt-large .sticker-tags     { gap: 2mm; margin-top: 1.5mm; }
        body.fmt-large .tag-route        { display: none; } /* вже показано у загині зверху */
        body.fmt-large .tag-individual   { font-size: 8pt; padding: 2px 6px; }
        body.fmt-large .circles-row      { gap: 4px; margin-top: 2mm; }
        body.fmt-large .meal-circle      { width: 20px; height: 20px; font-size: 9px; }
        body.fmt-large .no-icons-row     { gap: 5px; margin-top: 2mm; }
        body.fmt-large .no-icon-badge    { width: 24px; height: 24px; }
        body.fmt-large .no-icon-badge svg { width: 16px; height: 16px; }
        body.fmt-large .footer-text      { font-size: 7pt; }
        body.fmt-large .qr-placeholder canvas { width: 80px !important; height: 80px !important; }
        body.fmt-large .qr-placeholder.print-ready img { width: 80px !important; height: 80px !important; }

        /* Друк */
        @media print {
            body { background: white !important; }
            .no-print { display: none !important; }
            .label-sheet {
                margin: 0 !important;
                page-break-after: always;
                break-after: page;
            }
            .sticker-border { border-color: transparent !important; }
        }

        @page {
            size: A4 portrait;
            margin: 0;
        }
    </style>
</head>
<body>

<div class="no-print" style="display:flex;align-items:center;justify-content:center;gap:12px;flex-wrap:wrap;">
    {{-- Фільтр за слотом --}}
    <div style="display:flex;gap:6px;background:#1e293b;padding:6px;border-radius:12px;">
        <button onclick="filterSlot('all')"     id="btn-all"     class="filter-btn active">Всі (<span id="count-all">{{ count($manifests) }}</span>)</button>
        <button onclick="filterSlot('morning')" id="btn-morning" class="filter-btn">Ранок (<span id="count-morning">{{ collect($manifests)->where('is_evening', false)->count() }}</span>)</button>
        <button onclick="filterSlot('evening')" id="btn-evening" class="filter-btn evening-btn">Вечір (<span id="count-evening">{{ collect($manifests)->where('is_evening', true)->count() }}</span>)</button>
    </div>
    {{-- Вибір формату --}}
    <div style="display:flex;gap:6px;background:#1e293b;padding:6px;border-radius:12px;">
        <button onclick="switchFormat('small')" id="btn-fmt-small" class="filter-btn active" title="68×42мм — 21 шт/аркуш">
            ▪▪▪ Малий
        </button>
        <button onclick="switchFormat('large')" id="btn-fmt-large" class="filter-btn" title="70×99мм — 9 шт/аркуш">
            ▬▬ Великий
        </button>
    </div>
    <button onclick="window.print()" style="background:#334155;color:white;border:none;padding:14px 36px;border-radius:12px;font-size:15px;font-weight:900;cursor:pointer;letter-spacing:2px;text-transform:uppercase;">
        ДРУКУВАТИ (<span id="print-count">{{ count($manifests) }}</span> шт.)
    </button>
</div>

<style>
.filter-btn {
    background: transparent;
    color: #94a3b8;
    border: none;
    padding: 10px 20px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 900;
    cursor: pointer;
    text-transform: uppercase;
    letter-spacing: 1px;
    transition: all 0.15s;
}
.filter-btn.active {
    background: #fde047;
    color: #000;
}
.filter-btn.evening-btn.active {
    background: #1e3a5f;
    color: #94a3b8;
}
</style>

<div class="label-sheet">
    @foreach($manifests as $man)
        @php
            $project = \App\Models\Project::where('slug', $man['project'])->first();

            $brandColor = match($project?->color) {
                'success' => '#16a34a',
                'primary' => '#2563eb',
                'info'    => '#0891b2',
                'warning' => '#d97706',
                'danger'  => '#dc2626',
                default   => '#1e293b',
            };

            $projectLogo = ($project?->logo && file_exists(storage_path('app/public/' . $project->logo)))
                ? 'data:image/png;base64,' . base64_encode(file_get_contents(storage_path('app/public/' . $project->logo)))
                : null;

            $deliveryDate = \Carbon\Carbon::parse($date)->addDay()->format('d.m.Y');
        @endphp

        <div class="sticker" style="--brand-color: {{ $brandColor }};" data-slot="{{ $man['is_evening'] ? 'evening' : 'morning' }}" data-individual="{{ $man['is_individual'] ? '1' : '0' }}">
            <div class="sticker-border"></div>

            {{-- Маршрут зверху (перевернутий) — тільки великий формат --}}
            <div class="sticker-route">
                <div class="sticker-route-inner">
                    @if(!empty($man['ant_route_num']))
                        <span class="route-badge">М{{ $man['ant_route_num'] }}{{ !empty($man['ant_route_pos']) ? '-'.$man['ant_route_pos'] : '' }}</span>
                        @if(!empty($man['ant_driver']))
                            <span class="route-driver">{{ $man['ant_driver'] }}</span>
                        @endif
                    @endif
                </div>
            </div>

            {{-- Основний контент --}}
            <div class="sticker-body">

            {{-- Верх: дата + лого --}}
            <div class="sticker-top">
                <span class="date-badge">{{ $deliveryDate }}</span>
                @if($projectLogo)
                    <img src="{{ $projectLogo }}" class="project-logo" alt="">
                @endif
            </div>

            {{-- Клієнт --}}
            <div class="sticker-client">
                <span class="label-receiver">Отримувач:</span>
                <div class="client-name">{{ $man['client'] }}</div>
                <div class="sticker-tags">
                    <span class="tag-id">ID: {{ $man['client_id'] }}</span>
                    <span class="tag-kcal">{{ $man['calories'] ?? 0 }} ккал</span>
                    <span class="tag-slot {{ $man['is_evening'] ? 'evening' : 'morning' }}">
                        {{ $man['delivery_slot'] }}
                    </span>
                    @if($man['is_individual'])
                        <span class="tag-individual">ІНД</span>
                    @endif
                    @if(!empty($man['bundles']))
                        @foreach($man['bundles'] as $bundleName)
                            <span class="tag-bundle">{{ $bundleName }}</span>
                        @endforeach
                    @endif
                    @if(!empty($man['ant_route_num']))
                        <span class="tag-route">М{{ $man['ant_route_num'] }}
                            @if(!empty($man['ant_route_pos']))-{{ $man['ant_route_pos'] }}@endif
                            @if(!empty($man['ant_driver'])) · {{ $man['ant_driver'] }}@endif
                        </span>
                    @endif
                </div>
                @if(($man['address'] ?? 'Самовивіз') !== 'Самовивіз')
                    <span class="tag-address">{{ $man['address'] }}</span>
                @else
                    <span class="tag-address" style="color:#94a3b8;">Самовивіз</span>
                @endif
            </div>

            {{-- Кружечки та іконки — окремий flex-елемент, не стискається --}}
            @php
                $noCutlery = !($man['has_cutlery'] ?? true);
                $noWater   = ($man['water_option'] ?? '') === 'without_water';
                $noLemon   = ($man['water_option'] ?? '') === 'water_without_lemon';
            @endphp
            <div style="flex-shrink:0; display:flex; flex-direction:column; gap:1.5px;">
                @if(!empty($man['circles']))
                    <div class="circles-row" style="margin-top:0;">
                        @foreach($man['circles'] as $circle)
                            <div class="meal-circle" style="background-color: {{ $circle['color'] }};">{{ $circle['letter'] }}</div>
                        @endforeach
                    </div>
                @endif
                @if($noCutlery || $noWater || $noLemon)
                    <div class="no-icons-row" style="margin-top:0;">
                        @if($noCutlery)
                            <div class="no-icon-badge" title="Без приборів">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#1e293b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M3 2v7c0 1.1.9 2 2 2h4a2 2 0 0 0 2-2V2"/>
                                    <line x1="7" y1="2" x2="7" y2="22"/>
                                    <line x1="21" y1="15" x2="21" y2="22"/>
                                    <path d="M21 2v4a4 4 0 0 1-4 4h0v9"/>
                                    <line x1="2" y1="2" x2="22" y2="22" stroke="#dc2626" stroke-width="2.5"/>
                                </svg>
                            </div>
                        @endif
                        @if($noWater)
                            <div class="no-icon-badge" title="Без води">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#1e293b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M12 2.69l5.66 5.66a8 8 0 1 1-11.31 0z"/>
                                    <line x1="2" y1="2" x2="22" y2="22" stroke="#dc2626" stroke-width="2.5"/>
                                </svg>
                            </div>
                        @endif
                        @if($noLemon)
                            <div class="no-icon-badge" title="Вода без лимону">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#ca8a04" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="12" cy="12" r="9"/>
                                    <line x1="12" y1="3" x2="12" y2="21"/>
                                    <line x1="3.5" y1="7.5" x2="20.5" y2="16.5"/>
                                    <line x1="3.5" y1="16.5" x2="20.5" y2="7.5"/>
                                    <line x1="2" y1="2" x2="22" y2="22" stroke="#dc2626" stroke-width="2.5"/>
                                </svg>
                            </div>
                        @endif
                    </div>
                @endif
            </div>

            {{-- Підпис --}}
            <div class="sticker-footer">
                <div style="display:flex;flex-direction:column;justify-content:flex-end;">
                    <span class="footer-text">Смачного від {{ $project?->name ?? 'BRAND' }}!</span>
                    @if(!empty($man['menu_token']))
                        <span style="font-size:4pt;color:#dc2626;margin-top:0.8mm;letter-spacing:0.2px;font-weight:700;">Відскануй QR щоб побачити меню!</span>
                    @endif
                </div>
                @if(!empty($man['menu_token']))
                    <div class="qr-placeholder" data-url="{{ url('/menu/' . $man['menu_token']) }}" style="width:50px;height:50px;margin-right:1.5mm;margin-bottom:1mm;"></div>
                @endif
            </div>

            </div>{{-- /sticker-body --}}
        </div>
    @endforeach
</div>

<script>
var currentFormat = 'small';

function filterSlot(slot) {
    var stickers = document.querySelectorAll('.sticker');
    var visible = 0;
    stickers.forEach(function(s) {
        var show = slot === 'all' || s.dataset.slot === slot;
        s.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    document.getElementById('print-count').textContent = visible;
    document.querySelectorAll('.filter-btn:not([id^="btn-fmt"])').forEach(function(b) { b.classList.remove('active'); });
    document.getElementById('btn-' + slot).classList.add('active');
}

function switchFormat(fmt) {
    currentFormat = fmt;
    if (fmt === 'large') {
        document.body.classList.add('fmt-large');
        // Перемалювати QR більшого розміру
        document.querySelectorAll('.qr-placeholder').forEach(function(el) {
            el.innerHTML = '';
            el.style.width = '80px';
            el.style.height = '80px';
            var url = el.dataset.url;
            if (!url) return;
            new QRCode(el, { text: url, width: 80, height: 80, colorDark: '#000', colorLight: '#fff', correctLevel: QRCode.CorrectLevel.M });
        });
    } else {
        document.body.classList.remove('fmt-large');
        // Перемалювати QR малого розміру
        document.querySelectorAll('.qr-placeholder').forEach(function(el) {
            el.innerHTML = '';
            el.style.width = '50px';
            el.style.height = '50px';
            var url = el.dataset.url;
            if (!url) return;
            new QRCode(el, { text: url, width: 50, height: 50, colorDark: '#000', colorLight: '#fff', correctLevel: QRCode.CorrectLevel.M });
        });
    }
    document.getElementById('btn-fmt-small').classList.toggle('active', fmt === 'small');
    document.getElementById('btn-fmt-large').classList.toggle('active', fmt === 'large');
    setTimeout(fitClientNames, 50);
}

function fitClientNames() {
    document.querySelectorAll('.client-name').forEach(function(el) {
        el.style.fontSize = '';
        var parent = el.parentElement;
        var maxW = parent.offsetWidth;
        if (!maxW) return;
        var sizeNum = 11;
        var minSize = 6;
        while (el.scrollWidth > maxW && sizeNum > minSize) {
            sizeNum -= 0.5;
            el.style.fontSize = sizeNum + 'pt';
        }
    });
}

window.addEventListener('load', function () {
    document.querySelectorAll('.qr-placeholder').forEach(function (el) {
        var url = el.dataset.url;
        if (!url) return;
        new QRCode(el, { text: url, width: 50, height: 50, colorDark: '#000000', colorLight: '#ffffff', correctLevel: QRCode.CorrectLevel.M });
    });
    fitClientNames();
});

window.addEventListener('beforeprint', function () {
    var size = currentFormat === 'large' ? '80px' : '50px';
    document.querySelectorAll('.qr-placeholder').forEach(function (el) {
        var canvas = el.querySelector('canvas');
        if (!canvas) return;
        var img = el.querySelector('img');
        if (!img) { img = document.createElement('img'); el.appendChild(img); }
        img.src = canvas.toDataURL('image/png');
        img.style.width = size;
        img.style.height = size;
        el.classList.add('print-ready');
    });
});

window.addEventListener('afterprint', function () {
    document.querySelectorAll('.qr-placeholder.print-ready').forEach(function (el) {
        el.classList.remove('print-ready');
    });
});
</script>
</body>
</html>
