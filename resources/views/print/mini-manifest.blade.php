<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <title>На пакет — {{ \Carbon\Carbon::parse($date)->addDay()->format('d.m.Y') }}</title>
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

        /* A4: 210×297mm, 70×42mm стікери — 3 колонки × 7 рядків = 21 шт */
        .label-sheet {
            width: 210mm;
            min-height: 297mm;
            margin: 10px auto;
            background: white;
            display: flex;
            flex-wrap: wrap;
            align-content: flex-start;
            padding: 1.5mm 0 0 0;
        }

        .sticker {
            width: 70mm;
            height: 42mm;
            position: relative;
            overflow: hidden;
            padding: 3mm 3mm 2mm 5.5mm;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            border: none;
        }

        /* Кольорова ліва смуга */
        .sticker::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 4px;
            background: var(--brand-color, #000);
        }

        /* Тонка рамка для вирівнювання */
        .sticker-border {
            position: absolute;
            inset: 0;
            border: 0.3px solid #d1d5db;
            pointer-events: none;
        }

        /* Верх: дата + лого */
        .sticker-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
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
            height: 10mm;
            max-width: 18mm;
            object-fit: contain;
        }

        /* Клієнт */
        .sticker-client {
            border-bottom: 1.5px solid #0f172a;
            padding-bottom: 1.5mm;
        }

        .label-receiver {
            font-size: 5.5pt;
            color: #9ca3af;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            display: block;
            margin-bottom: 0.5mm;
        }

        .client-name {
            font-size: 13pt;
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
            gap: 2mm;
            margin-top: 1mm;
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

        .tag-address {
            font-size: 6pt;
            color: #6b7280;
            font-style: italic;
            font-weight: 600;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            min-width: 0;
        }

        /* Підпис */
        .sticker-footer {
            text-align: center;
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
                padding: 1.5mm 0 0 0 !important;
            }
            .sticker-border { border-color: transparent !important; }
        }

        @page {
            size: A4;
            margin: 0;
        }
    </style>
</head>
<body>

<div class="no-print">
    <button onclick="window.print()">
        ДРУКУВАТИ НАКЛЕЙКИ НА ПАКЕТ ({{ count($manifests) }} шт.)
    </button>
</div>

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

        <div class="sticker" style="--brand-color: {{ $brandColor }};">
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
                    <span class="tag-address">{{ $man['address'] ?? 'Самовивіз' }}</span>
                </div>
            </div>

            {{-- Підпис --}}
            <div class="sticker-footer">
                <span class="footer-text">Смачного від {{ $project?->name ?? 'BRAND' }}!</span>
            </div>
        </div>
    @endforeach
</div>

</body>
</html>
