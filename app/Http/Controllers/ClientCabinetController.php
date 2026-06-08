<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\OrderDay;
use Illuminate\Support\Carbon;

class ClientCabinetController extends Controller
{
    private function client(string $token): Client
    {
        return Client::where('cabinet_token', $token)->firstOrFail();
    }

    /** Профіль + короткий огляд */
    public function overview(string $token)
    {
        $client = $this->client($token);
        $latest = $client->orders()->with('projectData')->orderByDesc('id')->first();

        return view('cabinet.overview', [
            'client'  => $client,
            'token'   => $token,
            'project' => $latest?->projectData?->name ?? ($latest?->project ?? '—'),
            'ordersCount' => $client->orders()->where('status', '!=', 'cancelled')->count(),
        ]);
    }

    /** Історія замовлень */
    public function orders(string $token)
    {
        $client = $this->client($token);
        $orders = $client->orders()->with('tariff')->orderByDesc('start_date')->orderByDesc('id')->get();

        $statusMap = [
            'new'       => ['Нове',       'bg-blue-100 text-blue-700'],
            'active'    => ['Активне',    'bg-emerald-100 text-emerald-700'],
            'paused'    => ['На паузі',   'bg-amber-100 text-amber-700'],
            'finished'  => ['Завершено',  'bg-slate-200 text-slate-600'],
            'completed' => ['Виконано',   'bg-slate-200 text-slate-600'],
            'cancelled' => ['Скасовано',  'bg-rose-100 text-rose-700'],
        ];

        return view('cabinet.orders', compact('client', 'token', 'orders', 'statusMap'));
    }

    /** Оплати + баланс */
    public function payments(string $token)
    {
        $client = $this->client($token);
        $txns = $client->transactions()
            ->orderByDesc('date')->orderByDesc('id')
            ->get(['transactions.id', 'transactions.type', 'transactions.category', 'transactions.amount', 'transactions.date', 'transactions.comment']);

        return view('cabinet.payments', [
            'client'  => $client,
            'token'   => $token,
            'txns'    => $txns,
            'balance' => (float) $client->balance,
            'typeMap' => [
                'income'  => ['Оплата',      'text-emerald-600', '+'],
                'refund'  => ['Повернення',  'text-rose-600',    '−'],
                'expense' => ['Списання',    'text-rose-600',    '−'],
            ],
        ]);
    }

    /** Доставки */
    public function deliveries(string $token)
    {
        $client = $this->client($token);
        $orderIds = $client->orders()->pluck('id');

        $days = OrderDay::whereIn('order_id', $orderIds)
            ->orderByDesc('date')
            ->get()
            ->map(function (OrderDay $d) use ($client) {
                $addr = $d->address ?: $client->address;
                $apt  = $d->address_apartment ?: $client->address_apartment;
                return [
                    'date'      => $d->date ? Carbon::parse($d->date)->format('Y-m-d') : null,
                    'address'   => trim(($addr ?: '—') . ($apt ? ", кв. {$apt}" : '')),
                    'time'      => $d->delivery_time,
                    'completed' => (bool) $d->is_completed,
                ];
            });

        return view('cabinet.deliveries', compact('client', 'token', 'days'));
    }
}
