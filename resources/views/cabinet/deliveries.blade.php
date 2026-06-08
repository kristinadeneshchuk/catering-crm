@php($active = 'deliveries')
@extends('cabinet.layout')

@section('content')
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        @forelse($days as $d)
            <div class="flex items-center justify-between px-4 py-3 border-b border-slate-100 last:border-0">
                <div class="min-w-0">
                    <div class="font-medium text-sm">
                        {{ $d['date'] ? \Illuminate\Support\Carbon::parse($d['date'])->format('d.m.Y') : '—' }}
                        @if($d['time'])<span class="text-slate-400 font-normal">· {{ \Illuminate\Support\Str::of($d['time'])->substr(0,5) }}</span>@endif
                    </div>
                    <div class="text-xs text-slate-500 truncate">{{ $d['address'] }}</div>
                </div>
                <span class="text-xs font-semibold px-2.5 py-1 rounded-full whitespace-nowrap {{ $d['completed'] ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-500' }}">
                    {{ $d['completed'] ? 'Доставлено' : 'Заплановано' }}
                </span>
            </div>
        @empty
            <div class="px-4 py-6 text-center text-slate-400">Доставок ще немає</div>
        @endforelse
    </div>
@endsection
