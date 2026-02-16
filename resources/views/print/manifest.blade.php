<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <title>Маніфести — {{ \Carbon\Carbon::parse($date)->addDay()->format('d.m.Y') }}</title>
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
            /* Зелена рамка для Afood при друку */
            .border-avocado { border-color: #22c55e !important; } 
        }

        body { background: #f3f4f6; padding: 20px; }
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
        }
        /* Клас для зеленої обводки */
        .border-avocado { border-color: #22c55e; } 
    </style>
</head>
<body class="bg-gray-100">

@php
    // Використовуємо favicon.svg або avocado-logo.png
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
        🖨️ ДРУКУВАТИ МАНІФЕСТИ ({{ count($manifests) }} шт)
    </button>
</div>

<div class="sticker-grid">
    @foreach($manifests as $man)
        @php 
            $isUfit = str_contains(strtolower($man['project'] ?? ''), 'fit');
            // Зелений колір замість оранжевого
            $brandColor = $isUfit ? '#000' : '#22c55e'; 
            // Оновлений текст футера
            $footerName = $isUfit ? 'U-FIT' : 'Afood';
        @endphp
        
        <div class="sticker-box {{ $isUfit ? 'border-black' : 'border-avocado' }}">
            <div style="position: absolute; left: 0; top: 0; bottom: 0; width: 6px; border-right: 3px dotted {{ $brandColor }};"></div>
            
            <div class="pl-4 flex flex-col gap-3">
                <div class="flex justify-between items-start">
                    <div class="flex flex-col gap-1">
                        <span class="text-xs font-black bg-yellow-300 px-2 py-0.5 rounded shadow-sm inline-block">
                            {{ \Carbon\Carbon::parse($date)->addDay()->format('d.m.Y') }}
                        </span>
                    </div>
                    {{-- Збільшений розмір логотипу --}}
                    <div class="w-28 min-h-[45px] flex items-center justify-end">
                        @if($isUfit && $ufitLogo) 
                            <img src="{{ $ufitLogo }}" class="w-full object-contain"> 
                        @elseif($avocadoLogo) 
                            <img src="{{ $avocadoLogo }}" class="w-full object-contain"> 
                        @endif
                    </div>
                </div>

                <div class="border-b-2 border-slate-900 pb-2">
                    <span class="text-[9px] text-gray-400 font-bold uppercase block tracking-tighter">Отримувач:</span>
                    <h2 class="text-xl font-black uppercase leading-none tracking-tighter mb-1">{{ $man['client'] }}</h2>
                    <div class="flex justify-between items-center text-[11px] font-bold uppercase">
                        <span class="bg-slate-900 text-white px-2 py-0.5 rounded text-[12px] font-black">ID: {{ $man['client_id'] }}</span>
                        <span class="truncate ml-2 italic text-slate-500">{{ $man['address'] ?? 'Самовивіз' }}</span>
                    </div>
                </div>

                <div>
                    <div class="flex justify-between items-center mb-1">
                        <span class="text-[9px] font-black uppercase text-slate-400 italic">Склад раціону:</span>
                        <span class="text-[13px] font-black {{ $isUfit ? 'text-black' : 'text-green-600' }}">{{ $man['calories'] }} ккал</span>
                    </div>
                    <div class="border border-slate-100 rounded-lg overflow-hidden bg-slate-50/50">
                        @foreach($man['items'] as $item)
                            <div class="flex justify-between py-1 px-3 border-b border-white last:border-0 text-[12px] leading-tight">
                                <span class="font-bold text-slate-800">{{ $item['dish'] }}</span>
                                <span class="font-black text-slate-300 shrink-0 ml-4">{{ $item['weight'] }}г</span>
                            </div>
                        @endforeach
                    </div>
                </div>

                @if(!empty($man['comment']))
                    <div class="p-2 bg-red-50 border border-red-200 rounded-md">
                        <div class="text-[11px] font-black leading-tight uppercase text-red-700 italic">
                            {{ $man['comment'] }}
                        </div>
                    </div>
                @endif

                <div class="pt-2 text-center border-t border-slate-100">
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