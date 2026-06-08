@php($active = 'orders')
@extends('cabinet.layout')

@section('content')
    @forelse($orders as $o)
        @php($st = $statusMap[$o->status] ?? [$o->status, 'bg-slate-100 text-slate-600'])
        <div class="bg-white rounded-xl p-4 shadow-sm border border-slate-200 mb-3">
            <div class="flex items-center justify-between mb-2">
                <div class="font-semibold">{{ $o->tariff?->name ?? 'Замовлення' }}</div>
                <span class="text-xs font-semibold px-2.5 py-1 rounded-full {{ $st[1] }}">{{ $st[0] }}</span>
            </div>
            <div class="grid grid-cols-2 gap-y-1 text-sm">
                <div class="text-slate-500">Період</div>
                <div class="text-right font-medium">
                    {{ $o->start_date ? \Illuminate\Support\Carbon::parse($o->start_date)->format('d.m.y') : '—' }}
                    – {{ $o->end_date ? \Illuminate\Support\Carbon::parse($o->end_date)->format('d.m.y') : '—' }}
                </div>
                <div class="text-slate-500">Днів</div>
                <div class="text-right font-medium">{{ $o->duration ?? '—' }}</div>
                <div class="text-slate-500">Сума</div>
                <div class="text-right font-medium">{{ number_format((float)($o->final_price ?? $o->total_price), 0, '.', ' ') }} ₴</div>
                <div class="text-slate-500">Оплата</div>
                <div class="text-right font-medium {{ $o->is_paid ? 'text-emerald-600' : 'text-rose-600' }}">
                    {{ $o->is_paid ? 'Оплачено' : 'Не оплачено' }}
                </div>
            </div>
        </div>
    @empty
        <div class="bg-white rounded-xl p-6 text-center text-slate-400 border border-slate-200">Замовлень ще немає</div>
    @endforelse
@endsection
