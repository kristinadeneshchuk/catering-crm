<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <title>На пакет — {{ \Carbon\Carbon::parse($date)->addDay()->format('d.m.Y') }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        /* ГАРАНТІЯ КОЛЬОРУ ПРИ ДРУКУ */
        * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }

        @media print {
            .no-print { display: none !important; }
            body { background: white !important; padding: 0 !important; margin: 0 !important; }
            
            .sticker-grid { 
                display: grid !important; 
                grid-template-columns: 1fr 1fr !important; 
                gap: 5mm !important; 
                padding: 10mm !important;
            }
            .sticker-box { 
                break-inside: avoid !important; 
                height: auto !important; 
                border: 2px solid #000 !important; 
            }
            .border-avocado { border-color: #22c55e !important; } 
        }

        body { background: #f3f4f6; padding: 20px; font-family: sans-serif; }
        .sticker-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            max-width: 1000px;
            margin: 0 auto;
        }
        .sticker-box { 
            background: white; 
            border: 2px solid black; 
            position: relative; 
            display: flex; 
            flex-direction: column;
            padding: 15px;
            min-height: 120px;
        }
        .border-avocado { border-color: #22c55e; } 
    </style>
</head>
<body class="bg-gray-100">

@php
    $avocadoPath = public_path('images/favicon.svg'); 
    $ufitPath = public_path('images/u-fit-logo.png');

    $avocadoLogo = file_exists($avocadoPath) 
        ? 'data:image/svg+xml;base64,' . base64_encode(file_get_contents($avocadoPath)) 
        : null;

    $ufitLogo = file_exists($ufitPath) 
        ? 'data:image/png;base64,' . base64_encode(file_get_contents($ufitPath)) 
        : null;
@endphp

<div class="no-print text-center mb-10">
    <button onclick="window.print()" class="bg-slate-900 text-white px-12 py-4 rounded-2xl font-black uppercase tracking-widest text-lg shadow-xl">
        🖨️ ДРУКУВАТИ НАКЛЕЙКИ НА ПАКЕТ ({{ count($manifests) }} шт)
    </button>
</div>

<div class="sticker-grid">
    @foreach($manifests as $man)
        @php 
            $isUfit = str_contains(strtolower($man['project'] ?? ''), 'fit');
            $brandColor = $isUfit ? '#000' : '#22c55e'; 
            $footerName = $isUfit ? 'U-FIT' : 'Afood';
        @endphp
        
        <div class="sticker-box {{ $isUfit ? 'border-black' : 'border-avocado' }}">
            {{-- Пунктирна лінія зліва --}}
            <div style="position: absolute; left: 0; top: 0; bottom: 0; width: 6px; border-right: 3px dotted {{ $brandColor }};"></div>
            
            <div class="pl-4 flex flex-col justify-between h-full gap-3">
                
                {{-- Верхній рядок: Дата + Лого --}}
                <div class="flex justify-between items-start">
                    <div class="flex flex-col gap-1">
                        <span class="text-xs font-black bg-yellow-300 px-2 py-0.5 rounded shadow-sm inline-block text-black">
                            {{ \Carbon\Carbon::parse($date)->addDay()->format('d.m.Y') }}
                        </span>
                    </div>
                    <div class="w-28 min-h-[45px] flex items-center justify-end">
                        @if($isUfit && $ufitLogo) 
                            <img src="{{ $ufitLogo }}" class="w-full object-contain"> 
                        @elseif($avocadoLogo) 
                            <img src="{{ $avocadoLogo }}" class="w-full object-contain"> 
                        @endif
                    </div>
                </div>

                {{-- Основна інформація --}}
                <div class="border-b-2 border-slate-900 pb-2">
                    <span class="text-[9px] text-gray-400 font-bold uppercase block tracking-tighter">Отримувач:</span>
                    <h2 class="text-xl font-black uppercase leading-none tracking-tighter mb-1">{{ $man['client'] }}</h2>
                    <div class="flex justify-between items-center text-[11px] font-bold uppercase">
                        <div class="flex gap-2">
                            <span class="bg-slate-900 text-white px-2 py-0.5 rounded text-[12px] font-black">ID: {{ $man['client_id'] }}</span>
                            {{-- Плашка з калоріями --}}
                            <span class="text-white px-2 py-0.5 rounded text-[12px] font-black" style="background-color: {{ $brandColor }};">
                                {{ $man['calories'] ?? 0 }} ККАЛ
                            </span>
                        </div>
                        <span class="truncate ml-2 italic text-slate-500">{{ $man['address'] ?? 'Самовивіз' }}</span>
                    </div>
                </div>

                {{-- Футер --}}
                <div class="pt-2 text-center mt-auto">
                    <p class="text-[9px] font-black text-slate-300 uppercase tracking-[0.2em]">
                        Смачного від {{ $footerName }}!
                    </p>
                </div>

            </div>
        </div>
    @endforeach
</div>

</body>
</html>