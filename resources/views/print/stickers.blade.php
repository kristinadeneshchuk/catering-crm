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

        /* A4 page with 70x42mm labels, 3 cols x 7 rows = 21 per sheet */
        .label-sheet {
            width: 210mm;
            min-height: 297mm;
            margin: 10px auto;
            background: white;
            display: flex;
            flex-wrap: wrap;
            align-content: flex-start;
            justify-content: center;
            padding: 1.5mm 0 0 0;
        }

        .sticker {
            width: 67mm;
            height: 42mm;
            position: relative;
            overflow: hidden;
            padding: 2mm 2.5mm 2mm 4.5mm;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            border: 0.5px solid #cbd5e1;
        }

        .sticker-bar {
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 3px;
        }

        .sticker-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            padding-bottom: 1mm;
            border-bottom: 0.5px solid #e5e7eb;
            margin-bottom: 1mm;
        }

        .client-name {
            font-size: 9px;
            font-weight: 900;
            text-transform: uppercase;
            color: #0f172a;
            line-height: 1.1;
            max-width: 70%;
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

        .calories {
            font-size: 8px;
            font-weight: 700;
            background: #f1f5f9;
            color: #64748b;
            padding: 1px 4px;
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

        .changes-box {
            background: #fef2f2;
            border: 0.5px solid #fecaca;
            border-radius: 2px;
            padding: 1px 3px;
            margin-top: 1mm;
        }

        .change-item {
            font-size: 7px;
            font-weight: 800;
            color: #dc2626;
            text-transform: uppercase;
            line-height: 1.2;
        }

        .weight-row {
            text-align: right;
            margin-top: auto;
            padding-top: 1mm;
            border-top: 0.5px dashed #e5e7eb;
        }

        .weight-value {
            font-size: 16px;
            font-weight: 900;
        }

        .weight-unit {
            font-size: 8px;
            font-weight: 600;
            color: #9ca3af;
            margin-left: 1px;
        }

        .brand-watermark {
            position: absolute;
            bottom: 1mm;
            left: 5mm;
            font-size: 6px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: -0.3px;
            opacity: 0.08;
        }

        @media print {
            .no-print { display: none !important; }
            body { background: white !important; }

            .label-sheet {
                margin: 0;
                padding: 1.5mm 0 0 0;
                min-height: auto;
                page-break-after: always;
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
<body>

<div class="no-print">
    <button onclick="window.print()">
        РОЗДРУКУВАТИ ВСІ СТІКЕРИ ({{ count($stickers) }} шт.)
    </button>
</div>

@php
    $chunks = array_chunk($stickers, 21);
@endphp

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
            @endphp

            <div class="sticker">
                <div class="sticker-bar" style="background-color: {{ $brandColor }};"></div>

                <div>
                    <div class="sticker-header">
                        <div>
                            <div class="client-name">{{ $sticker['client'] }}</div>
                            <span class="client-id">ID: {{ $sticker['client_id'] }}</span>
                        </div>
                        <span class="calories">{{ $sticker['calories'] }}</span>
                    </div>

                    <div class="meal-row">
                        <span class="meal-type" style="color: {{ $brandColor }};">{{ $sticker['meal'] }}</span>
                        <span class="meal-date">{{ \Carbon\Carbon::parse($date)->addDay()->format('d.m') }}</span>
                    </div>

                    <div class="dish-name">{{ $sticker['dish'] }}</div>

                    @if(!empty($sticker['changes']))
                        <div class="changes-box">
                            @foreach($sticker['changes'] as $change)
                                <div class="change-item">{{ $change }}</div>
                            @endforeach
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

</body>
</html>
