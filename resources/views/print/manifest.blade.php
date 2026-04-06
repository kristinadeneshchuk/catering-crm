<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <title>Маніфести — {{ \Carbon\Carbon::parse($date)->addDay()->format('d.m.Y') }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        /* ЗАГАЛЬНІ СТИЛІ */
        * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; box-sizing: border-box; }
        body { background: #f3f4f6; padding: 20px; font-family: sans-serif; }
        .sticker-box { background: white; position: relative; display: flex; flex-direction: column; padding: 12px; } /* Трохи зменшив внутрішній відступ з 15 до 12px */
        
        @media print {
            .no-print { display: none !important; }
            body { background: white !important; padding: 0 !important; margin: 0 !important; }
        }

        /* ========================================= */
        /* ЛОГІКА РОЗМІТКИ ЗАЛЕЖНО ВІД ВИБОРУ        */
        /* ========================================= */
        
        @if($layout === '105x99')
            /* 📄 ФІКСОВАНИЙ РОЗМІР 105x99 мм НА АРКУШІ А4 */
            .sticker-grid {
                display: flex;
                flex-wrap: wrap;
                width: 210mm;
                margin: 0 auto;
                background: white;
            }
            .sticker-box {
                width: 105mm;
                height: 99mm;
                overflow: hidden;
                border: 1px dashed #cbd5e1 !important;
            }

            .sticker-box.is-compact { padding: 8px !important; }
            .sticker-box.is-compact .logo-wrap { min-height: 24px !important; height: 24px !important; }
            .sticker-box.is-compact h2.client-name { font-size: 14px !important; line-height: 1.1 !important; }

            @media print {
                @page { size: A4; margin: 0; }
                .sticker-grid { width: 100% !important; max-width: 210mm !important; }
                .sticker-box { page-break-inside: avoid; }
            }

        @else
            /* 📄 СТАНДАРТНИЙ А4 */
            .sticker-grid { display: grid; grid-template-columns: repeat(2, 480px); gap: 15px; justify-content: center; margin: 0 auto; }
            .sticker-box { border: 2px solid black !important; }
            @media print {
                @page { size: A4; margin: 10mm; }
                .sticker-grid { display: grid !important; grid-template-columns: repeat(2, 480px) !important; gap: 5mm !important; padding: 0 !important; justify-content: center !important; }
                .sticker-box { break-inside: avoid !important; height: auto !important; }
            }
        @endif

    </style>
</head>
<body class="bg-gray-100">

<div class="no-print mb-10 flex flex-col md:flex-row justify-center items-center gap-4 bg-white p-4 rounded-2xl shadow-sm max-w-4xl mx-auto border border-gray-200">
    <div class="flex items-center gap-3">
        <label class="font-bold text-gray-500 uppercase text-xs">Розмір:</label>
        <select onchange="window.location.href=this.value" class="bg-gray-50 border border-gray-300 text-slate-800 font-bold px-4 py-3 rounded-xl outline-none focus:border-slate-900 cursor-pointer transition">
            <option value="?date={{ $date }}&layout=default" {{ $layout === 'default' ? 'selected' : '' }}>Стандартний вигляд (А4)</option>
            <option value="?date={{ $date }}&layout=105x99" {{ $layout === '105x99' ? 'selected' : '' }}>Фіксований: 105 x 99 мм</option>
        </select>
    </div>

    <div style="display:flex;gap:6px;background:#1e293b;padding:6px;border-radius:12px;">
        <button onclick="filterSlot('all')"     id="btn-all"     class="mf-filter active">Всі (<span id="count-all">{{ count($manifests) }}</span>)</button>
        <button onclick="filterSlot('morning')" id="btn-morning" class="mf-filter">Ранок (<span id="count-morning">{{ collect($manifests)->where('is_evening', false)->count() }}</span>)</button>
        <button onclick="filterSlot('evening')" id="btn-evening" class="mf-filter evening-btn">Вечір (<span id="count-evening">{{ collect($manifests)->where('is_evening', true)->count() }}</span>)</button>
    </div>

    <button onclick="window.print()" class="bg-slate-900 text-white px-10 py-3 rounded-xl font-black uppercase tracking-widest shadow-xl hover:bg-slate-800 transition">
        ДРУКУВАТИ (<span id="print-count">{{ count($manifests) }}</span> шт)
    </button>
</div>

<style>
.mf-filter { background:transparent;color:#94a3b8;border:none;padding:10px 20px;border-radius:8px;font-size:13px;font-weight:900;cursor:pointer;text-transform:uppercase;letter-spacing:1px;transition:all 0.15s; }
.mf-filter.active { background:#fde047;color:#000; }
.mf-filter.evening-btn.active { background:#1e3a5f;color:#94a3b8; }
</style>

<div class="sticker-grid">
    @foreach($manifests as $man)
        @php 
            $project = \App\Models\Project::where('slug', $man['project'])->first();
            
            $brandColor = match($project?->color) {
                'success' => '#22c55e',
                'primary' => '#3b82f6',
                'info'    => '#06b6d4',
                'warning' => '#eab308',
                'danger'  => '#ef4444',
                default   => '#000000',
            };

            $projectLogo = ($project?->logo && file_exists(storage_path('app/public/' . $project->logo))) 
                ? 'data:image/png;base64,' . base64_encode(file_get_contents(storage_path('app/public/' . $project->logo))) 
                : null;
            
            // Вмикаємо стиснення ТІЛЬКИ якщо дуже багато страв (5 і більше)
            $itemCount = count($man['items'] ?? []);
            $hasComment = !empty($man['comment']);
            $isCompact = $itemCount >= 5 || ($itemCount == 4 && strlen($man['comment'] ?? '') > 40);
        @endphp
        
        <div class="sticker-box {{ $layout === '105x99' && $isCompact ? 'is-compact' : '' }}" data-slot="{{ $man['is_evening'] ? 'evening' : 'morning' }}" style="border-color: {{ $brandColor }} !important;">
            <div style="position: absolute; left: 0; top: 0; bottom: 0; width: 6px; background-color: {{ $brandColor }};"></div>
            
            <div class="pl-3 flex flex-col h-full w-full justify-between">
                
                <div>
                    <div class="flex justify-between items-start mb-1.5">
                        <span class="text-xs font-black bg-yellow-300 px-2 py-0.5 rounded shadow-sm inline-block text-black">
                            {{ \Carbon\Carbon::parse($date)->addDay()->format('d.m.Y') }}
                        </span>
                        <div class="w-24 logo-wrap min-h-[35px] flex items-center justify-end">
                            @if($projectLogo)
                                <img src="{{ $projectLogo }}" class="w-full object-contain">
                            @endif
                        </div>
                    </div>

                    <div class="border-b-2 border-slate-900 pb-1.5 mb-2">
                        <span class="text-[9px] text-gray-400 font-bold uppercase block tracking-tighter mb-0.5">Отримувач:</span>
                        <h2 class="client-name text-[17px] font-black uppercase leading-none tracking-tighter mb-1">{{ $man['client'] }}</h2>
                        <div class="flex items-center text-[10px] font-bold uppercase">
                            <span class="bg-slate-900 text-white px-1.5 py-0.5 rounded font-black shrink-0">ID: {{ $man['client_id'] }}</span>
                        </div>
                        <div class="text-[9px] italic text-slate-500 mt-0.5 leading-tight">{{ $man['address'] ?? 'Самовивіз' }}</div>
                    </div>
                </div>

                <div class="flex-grow flex flex-col justify-start">
                    <div class="flex justify-between items-end mb-1">
                        <span class="text-[9px] font-black uppercase text-slate-400 italic">Склад раціону:</span>
                        <div class="flex items-baseline gap-2">
                            <span class="text-[10px] font-bold text-slate-500">Б:{{ $man['nutrition']['b'] ?? 0 }} Ж:{{ $man['nutrition']['j'] ?? 0 }} В:{{ $man['nutrition']['u'] ?? 0 }}</span>
                            <span class="text-[13px] font-black" style="color: {{ $brandColor }};">{{ $man['calories'] }} ккал</span>
                        </div>
                    </div>

                    <div class="border border-slate-100 rounded-lg overflow-hidden bg-slate-50/50">
                        @foreach($man['items'] as $item)
                            <div class="flex justify-between py-1 px-2.5 border-b border-white last:border-0 text-[11px] leading-tight">
                                <span class="font-bold text-slate-800">{{ $item['dish'] }}</span>
                                <span class="font-black text-slate-300 shrink-0 ml-3">{{ $item['weight'] }}г</span>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="flex flex-col mt-auto pt-1">
                    <div class="flex items-center gap-2 mb-1 flex-wrap">
                        <span class="text-[10px] font-bold text-slate-500 uppercase">
                            {{ $man['has_cutlery'] ? 'З приборами' : 'Без приборів' }}
                        </span>
                        @if(!empty($man['water_option']))
                            <span class="text-[10px] font-bold uppercase px-1.5 py-0.5 rounded"
                                  style="background:#fef3c7;color:#92400e;">
                                {{ $man['water_option'] }}
                            </span>
                        @endif
                        @if(!empty($man['circles']))
                            <div class="flex items-center gap-1 ml-1">
                                @foreach($man['circles'] as $circle)
                                    <div style="width:16px;height:16px;border-radius:50%;background:{{ $circle['color'] }};display:flex;align-items:center;justify-content:center;font-size:7px;font-weight:900;color:white;flex-shrink:0;line-height:1;">
                                        {{ $circle['letter'] }}
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    @if($hasComment)
                        <div class="px-2 py-1 bg-red-50 border border-red-200 rounded mb-1">
                            <div class="text-[9px] font-black leading-tight uppercase text-red-700 italic">{{ $man['comment'] }}</div>
                        </div>
                    @endif

                    <div class="pt-1.5 border-t border-slate-100 mt-1">
                        <p class="text-[8px] font-black text-slate-300 uppercase tracking-[0.2em]">Смачного від {{ $project?->name ?? 'BRAND' }}!</p>
                    </div>
                </div>

            </div>
        </div>
    @endforeach
</div>
<script>
function filterSlot(slot) {
    var boxes = document.querySelectorAll('.sticker-box');
    var visible = 0;
    boxes.forEach(function(b) {
        var show = slot === 'all' || b.dataset.slot === slot;
        b.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    document.getElementById('print-count').textContent = visible;
    document.querySelectorAll('.mf-filter').forEach(function(b) { b.classList.remove('active'); });
    document.getElementById('btn-' + slot).classList.add('active');
}
</script>
</body>
</html>