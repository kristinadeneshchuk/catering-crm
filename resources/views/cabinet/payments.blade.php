@php($active = 'payments')
@extends('cabinet.layout')

@section('content')
    <div class="bg-white rounded-xl p-4 shadow-sm border border-slate-200 mb-4">
        <div class="text-xs uppercase tracking-wider text-slate-400 font-semibold mb-1">Поточний баланс</div>
        <div class="text-2xl font-bold {{ $balance < 0 ? 'text-rose-600' : 'text-emerald-600' }}">
            {{ number_format($balance, 0, '.', ' ') }} ₴
        </div>
        @if($balance < 0)
            <div class="text-xs text-rose-500 mt-1">Заборгованість</div>
        @endif
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        @forelse($txns as $t)
            @php($tm = $typeMap[$t->type] ?? [$t->category ?: $t->type, 'text-slate-600', ''])
            <div class="flex items-center justify-between px-4 py-3 border-b border-slate-100 last:border-0">
                <div>
                    <div class="font-medium text-sm">{{ $t->category ?: $tm[0] }}</div>
                    <div class="text-xs text-slate-400">{{ $t->date ? \Illuminate\Support\Carbon::parse($t->date)->format('d.m.Y') : '' }}</div>
                </div>
                <div class="font-semibold {{ $tm[1] }}">{{ $tm[2] }}{{ number_format((float)$t->amount, 0, '.', ' ') }} ₴</div>
            </div>
        @empty
            <div class="px-4 py-6 text-center text-slate-400">Платежів ще немає</div>
        @endforelse
    </div>
@endsection
