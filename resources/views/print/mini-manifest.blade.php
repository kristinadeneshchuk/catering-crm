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

        /* A4: 210×297mm. Принтер не друкує ліві 7mm.
           Доступна ширина: 203mm. Стікер: 67.67mm × 42mm. 3×7 = 21 шт/лист */
        .label-sheet {
            width: 210mm;
            height: 297mm;
            padding-left: 7mm;
            margin: 0 auto;
            background: white;
            display: grid;
            grid-template-columns: repeat(3, 67.67mm);
            grid-template-rows: repeat(7, 42mm);
            align-content: start;
            justify-content: start;
        }

        .sticker {
            width: 67.67mm;
            height: 42mm;
            position: relative;
            overflow: hidden;
            padding: 1.5mm 2mm 1.5mm 2mm;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            border: none;
            break-inside: avoid;
            page-break-inside: avoid;
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
            padding-bottom: 1mm;
            overflow: hidden;
            flex-shrink: 1;
            min-height: 0;
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
        }

        /* Теги: ID, ккал, адреса */
        .sticker-tags {
            display: flex;
            align-items: center;
            gap: 1.5mm;
            margin-top: 0.5mm;
            flex-wrap: nowrap;
            overflow: hidden;
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
        }

        .qr-placeholder {
            margin-right: 2mm;
            margin-bottom: 1.5mm;
        }

        .qr-placeholder img,
        .qr-placeholder canvas {
            display: block;
            width: 50px !important;
            height: 50px !important;
        }

        .footer-text {
            font-size: 5pt;
            font-weight: 900;
            color: #cbd5e1;
            text-transform: uppercase;
            letter-spacing: 1.5px;
        }

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
    <div style="display:flex;gap:6px;background:#1e293b;padding:6px;border-radius:12px;">
        <button onclick="filterSlot('all')"   id="btn-all"     class="filter-btn active">Всі (<span id="count-all">{{ count($manifests) }}</span>)</button>
        <button onclick="filterSlot('morning')" id="btn-morning" class="filter-btn">Ранок (<span id="count-morning">{{ collect($manifests)->where('is_evening', false)->count() }}</span>)</button>
        <button onclick="filterSlot('evening')" id="btn-evening" class="filter-btn evening-btn">Вечір (<span id="count-evening">{{ collect($manifests)->where('is_evening', true)->count() }}</span>)</button>
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

        <div class="sticker" style="--brand-color: {{ $brandColor }};" data-slot="{{ $man['is_evening'] ? 'evening' : 'morning' }}">
            <div class="sticker-border"></div>

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

            {{-- Підпис --}}
            <div class="sticker-footer">
                <span class="footer-text">Смачного від {{ $project?->name ?? 'BRAND' }}!</span>
                @if(!empty($man['menu_token']))
                    <div class="qr-placeholder" data-url="{{ url('/menu/' . $man['menu_token']) }}" style="width:50px;height:50px;flex-shrink:0;margin-right:1.5mm;margin-bottom:1mm;"></div>
                @endif
            </div>
        </div>
    @endforeach
</div>

<script>
function filterSlot(slot) {
    var stickers = document.querySelectorAll('.sticker');
    var visible = 0;
    stickers.forEach(function(s) {
        var show = slot === 'all' || s.dataset.slot === slot;
        s.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    document.getElementById('print-count').textContent = visible;

    document.querySelectorAll('.filter-btn').forEach(function(b) { b.classList.remove('active'); });
    document.getElementById('btn-' + slot).classList.add('active');
}

window.addEventListener('load', function () {
    document.querySelectorAll('.qr-placeholder').forEach(function (el) {
        var url = el.dataset.url;
        if (!url) return;
        new QRCode(el, {
            text: url,
            width: 50,
            height: 50,
            colorDark: '#000000',
            colorLight: '#ffffff',
            correctLevel: QRCode.CorrectLevel.M
        });
    });
});

window.addEventListener('beforeprint', function () {
    document.querySelectorAll('.qr-placeholder canvas').forEach(function (canvas) {
        var img = document.createElement('img');
        img.src = canvas.toDataURL('image/png');
        img.style.width = '50px';
        img.style.height = '50px';
        canvas.parentNode.replaceChild(img, canvas);
    });
});
</script>
</body>
</html>
