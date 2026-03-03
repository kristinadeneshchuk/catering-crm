<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <title>Друк стікерів — {{ \Carbon\Carbon::parse($date)->addDay()->format('d.m.Y') }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; color-adjust: exact !important; }
        @media print {
            .no-print { display: none !important; }
            body { background: white !important; padding: 0 !important; margin: 0 !important; }
            .sticker-container { display: grid !important; grid-template-columns: 1fr 1fr; gap: 10px; padding: 10mm; }
            .sticker-page { break-inside: avoid !important; page-break-inside: avoid !important; margin-bottom: 10px !important; border: 1px solid #e5e7eb !important; }
        }
        body { background: #f3f4f6; padding: 20px; }
        .sticker-container { display: grid; grid-template-columns: repeat(auto-fill, minmax(80mm, 1fr)); gap: 15px; max-width: 1000px; margin: 0 auto; }
        .sticker-page { background: white; width: 80mm; min-height: 60mm; position: relative; box-shadow: 0 2px 4px rgba(0,0,0,0.05); border-radius: 8px; overflow: hidden; display: flex; flex-direction: column; }
    </style>
</head>
<body>

<div class="no-print mb-8 text-center">
    <button onclick="window.print()" class="bg-slate-800 hover:bg-slate-900 text-white px-10 py-4 rounded-2xl font-black shadow-xl transition-all uppercase tracking-widest text-lg">
        🖨️ РОЗДРУКУВАТИ ВСІ СТІКЕРИ ({{ count($stickers) }} шт.)
    </button>
</div>

<div class="sticker-container">
    @foreach($stickers as $sticker)
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

        <div class="sticker-page p-3">
            <div style="position: absolute; left: 0; top: 0; bottom: 0; width: 6px; background-color: {{ $brandColor }};"></div>

            <div class="pl-3 w-full h-full flex flex-col justify-between">
                <div>
                    <div class="flex justify-between items-start border-b border-gray-100 pb-1 mb-1">
                        <div class="flex flex-col max-w-[75%]">
                            <span class="text-[14px] font-black leading-tight text-slate-900 uppercase">{{ $sticker['client'] }}</span>
                            <div class="mt-0.5"><span class="bg-slate-800 text-white text-[9px] px-1.5 py-0.5 rounded font-bold uppercase">ID: {{ $sticker['client_id'] }}</span></div>
                        </div>
                        <span class="font-bold text-[10px] bg-slate-100 text-slate-500 px-1.5 py-0.5 rounded">{{ $sticker['calories'] }}</span>
                    </div>

                    <div class="flex items-center gap-2 mb-2">
                        <span class="text-[11px] font-black uppercase" style="color: {{ $brandColor }};">{{ $sticker['meal'] }}</span>
                        <span class="text-[10px] font-bold bg-yellow-300 px-1.5 py-0.5 rounded text-black">{{ \Carbon\Carbon::parse($date)->addDay()->format('d.m') }}</span>
                    </div>

                    <h2 class="text-[16px] leading-tight font-extrabold text-slate-900 mb-2">{{ $sticker['dish'] }}</h2>

                    @if(!empty($sticker['changes']))
                        <div class="bg-red-50 border border-red-100 rounded p-1.5 mt-1">
                            @foreach($sticker['changes'] as $change)
                                <div class="text-[11px] font-black text-red-600 uppercase leading-snug mb-0.5 last:mb-0">{{ $change }}</div>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div class="text-right pt-1 border-t border-dashed border-gray-100 mt-auto">
                    <span class="text-2xl font-black" style="color: {{ $brandColor }};">
                        {{ $sticker['weight'] }}<span class="text-sm ml-0.5 font-bold text-gray-400">г</span>
                    </span>
                </div>
            </div>
            
            <div class="absolute bottom-1 left-4 opacity-10 text-[8px] font-bold uppercase tracking-tighter">
                {{ $project?->name ?? 'BRAND' }} DELIVERY
            </div>
        </div>
    @endforeach
</div>
</body>
</html>