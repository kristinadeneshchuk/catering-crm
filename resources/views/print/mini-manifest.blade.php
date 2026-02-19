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
            
            .sticker-grid { 
                display: grid !important; 
                grid-template-columns: 1fr 1fr !important; 
                gap: 5mm !important; 
                padding: 10mm !important;
            }
            .sticker-box { 
                break-inside: avoid !important; 
            }
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
            border: 2px solid #22c55e; /* Зелена рамка за замовчуванням */
            position: relative; 
            display: flex; 
            flex-direction: column;
            padding: 15px;
            min-height: 120px;
        }
        .ufit-border { border-color: #000 !important; }
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
        
        <div class="sticker-box {{ $isUfit ? 'ufit-border' : '' }}">
            {{-- Пунктирна лінія зліва --}}
            <div style="position: absolute; left: 0; top: 0; bottom: 0; width: 6px; border-right: 3px dotted {{ $brandColor }};"></div>
            
            <div class="pl-4 flex flex-col justify-between h-full">
                
                {{-- Верхній рядок: Дата + Лого --}}
                <div class="flex justify-between items-start mb-2">
                    <span class="text-[14px] font-black bg-yellow-300 px-3 py-1 rounded shadow-sm text-black">
                        {{ \Carbon\Carbon::parse($date)->addDay()->format('d.m.Y') }}
                    </span>
                    
                    <div class="w-24 h-8 flex items-center justify-end">
                        @if($isUfit && $ufitLogo) 
                            <img src="{{ $ufitLogo }}" class="h-full object-contain"> 
                        @elseif($avocadoLogo) 
                            <img src="{{ $avocadoLogo }}" class="h-full object-contain"> 
                        @endif
                    </div>
                </div>

                {{-- Основна інформація --}}
                <div class="border-b-2 border-slate-900 pb-3 mt-2">
                    <span class="text-[10px] text-gray-400 font-bold uppercase block tracking-tighter mb-1">Отримувач:</span>
                    <h2 class="text-2xl font-black uppercase leading-none tracking-tighter mb-2">{{ $man['client'] }}</h2>
                    
                    <div class="flex justify-between items-center text-[12px] font-bold uppercase mt-2">
                        <span class="bg-slate-900 text-white px-2 py-0.5 rounded text-[14px] font-black">ID: {{ $man['client_id'] }}</span>
                        <span class="truncate ml-2 text-slate-600 tracking-tighter">{{ $man['address'] ?? 'Самовивіз' }}</span>
                    </div>
                </div>

                {{-- Футер --}}
                <div class="pt-3 text-center mt-auto">
                    <p class="text-[10px] font-black text-slate-300 uppercase tracking-[0.3em]">
                        Смачного від {{ $footerName }}!
                    </p>
                </div>

            </div>
        </div>
    @endforeach
</div>

</body>
</html>