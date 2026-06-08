@php($active = 'overview')
@extends('cabinet.layout')

@section('content')
    <div class="grid sm:grid-cols-2 gap-4">
        <div class="bg-white rounded-xl p-4 shadow-sm border border-slate-200">
            <div class="text-xs uppercase tracking-wider text-slate-400 font-semibold mb-1">Проєкт</div>
            <div class="text-lg font-semibold">{{ $project }}</div>
        </div>
        <div class="bg-white rounded-xl p-4 shadow-sm border border-slate-200">
            <div class="text-xs uppercase tracking-wider text-slate-400 font-semibold mb-1">Баланс</div>
            <div class="text-lg font-semibold {{ $client->balance < 0 ? 'text-rose-600' : 'text-emerald-600' }}">
                {{ number_format($client->balance, 0, '.', ' ') }} ₴
            </div>
        </div>
        <div class="bg-white rounded-xl p-4 shadow-sm border border-slate-200">
            <div class="text-xs uppercase tracking-wider text-slate-400 font-semibold mb-1">Замовлень усього</div>
            <div class="text-lg font-semibold">{{ $ordersCount }}</div>
        </div>
        <div class="bg-white rounded-xl p-4 shadow-sm border border-slate-200">
            <div class="text-xs uppercase tracking-wider text-slate-400 font-semibold mb-1">Ціль калорій</div>
            <div class="text-lg font-semibold">{{ $client->target_kcal ? $client->target_kcal . ' ккал' : '—' }}</div>
        </div>
    </div>

    <div class="bg-white rounded-xl p-4 shadow-sm border border-slate-200 mt-4">
        <div class="text-xs uppercase tracking-wider text-slate-400 font-semibold mb-3">Дані</div>
        <dl class="divide-y divide-slate-100 text-sm">
            <div class="flex justify-between py-2"><dt class="text-slate-500">Телефон</dt><dd class="font-medium">{{ $client->phone ?: '—' }}</dd></div>
            <div class="flex justify-between py-2"><dt class="text-slate-500">Адреса</dt><dd class="font-medium text-right">{{ $client->address ?: '—' }}{{ $client->address_apartment ? ', кв. ' . $client->address_apartment : '' }}</dd></div>
            <div class="flex justify-between py-2"><dt class="text-slate-500">Прибори</dt><dd class="font-medium">{{ $client->has_cutlery ? 'Так' : 'Ні' }}</dd></div>
            @if($client->delivery_comment)
                <div class="flex justify-between py-2"><dt class="text-slate-500">Коментар до доставки</dt><dd class="font-medium text-right">{{ $client->delivery_comment }}</dd></div>
            @endif
        </dl>
    </div>
@endsection
