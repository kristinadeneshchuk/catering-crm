<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <title>На пакет — {{ \Carbon\Carbon::parse($date)->addDay()->format('d.m.Y') }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        @media print {
            .no-print { display: none !important; }
            body { background: white !important; padding: 0 !important; margin: 0 !important; }
            .sticker-grid { display: grid !important; grid-template-columns: 1fr 1fr !important; gap: 5mm !important; padding: 10mm !important; }
            .sticker-box { break-inside: avoid !important; height: auto !important; border: 2px solid #000 !important; }
        }
        body { background: #f3f4f6; padding: 20px; font-family: sans-serif; }
        .sticker-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; max-width: 1000px; margin: 0 auto; }
        .sticker-box { background: white; border: 2px solid black; position: relative; display: flex; flex-direction: column; padding: 15px; min-height: 120px; }
    </style>
</head>
<body class="bg-gray-100">

<div class="no-print text-center mb-10">
    <button onclick="window.print()" class="bg-slate-900 text-white px-12 py-4 rounded-2xl font-black uppercase tracking-widest text-lg shadow-xl">
        🖨️ ДРУКУВАТИ НАКЛЕЙКИ НА ПАКЕТ ({{ count($manifests) }} шт)
    </button>
</div>

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
        @endphp
        
        <div class="sticker-box" style="border-color: {{ $brandColor }} !important;">
            <div style="position: absolute; left: 0; top: 0; bottom: 0; width: 6px; border-right: 3px dotted {{ $brandColor }};"></div>
            
            <div class="pl-4 flex flex-col justify-between h-full gap-3">
                <div class="flex justify-between items-start">
                    <span class="text-xs font-black bg-yellow-300 px-2 py-0.5 rounded shadow-sm inline-block text-black">
                        {{ \Carbon\Carbon::parse($date)->addDay()->format('d.m.Y') }}
                    </span>
                    <div class="w-28 min-h-[45px] flex items-center justify-end">
                        @if($projectLogo)
                            <img src="{{ $projectLogo }}" class="w-full object-contain">
                        @endif
                    </div>
                </div>

                <div class="border-b-2 border-slate-900 pb-2">
                    <span class="text-[9px] text-gray-400 font-bold uppercase block tracking-tighter">Отримувач:</span>
                    <h2 class="text-xl font-black uppercase leading-none tracking-tighter mb-1">{{ $man['client'] }}</h2>
                    <div class="flex justify-between items-center text-[11px] font-bold uppercase">
                        <div class="flex gap-2">
                            <span class="bg-slate-900 text-white px-2 py-0.5 rounded text-[12px] font-black">ID: {{ $man['client_id'] }}</span>
                            <span class="text-white px-2 py-0.5 rounded text-[12px] font-black" style="background-color: {{ $brandColor }};">
                                {{ $man['calories'] ?? 0 }} ККАЛ
                            </span>
                        </div>
                        <span class="truncate ml-2 italic text-slate-500">{{ $man['address'] ?? 'Самовивіз' }}</span>
                    </div>
                </div>

                <div class="pt-2 text-center mt-auto">
                    <p class="text-[9px] font-black text-slate-300 uppercase tracking-[0.2em]">Смачного від {{ $project?->name ?? 'BRAND' }}!</p>
                </div>
            </div>
        </div>
    @endforeach
</div>
</body>
</html>