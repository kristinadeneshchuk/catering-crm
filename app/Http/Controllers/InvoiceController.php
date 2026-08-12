<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Публічна віддача рахунку.
 *
 * Посилання відкривається без авторизації — його пересилають клієнту в
 * месенджер. Захист через непередбачуваний token, як у меню замовлення
 * (orders.menu_token) і кабінеті клієнта.
 */
class InvoiceController extends Controller
{
    public function pdf(string $token)
    {
        $invoice = Invoice::where('token', $token)
            ->with(['client', 'order.tariff'])
            ->firstOrFail();

        return Pdf::loadView('print.invoice', $this->data($invoice))
            ->setPaper('a4')
            ->stream("rakhunok-{$invoice->sequence}.pdf");
    }

    /** HTML-версія — щоб подивитись у браузері, не завантажуючи файл. */
    public function show(string $token)
    {
        $invoice = Invoice::where('token', $token)
            ->with(['client', 'order.tariff'])
            ->firstOrFail();

        return view('print.invoice', $this->data($invoice));
    }

    protected function data(Invoice $invoice): array
    {
        $order    = $invoice->order;
        $subtotal = (float) ($order?->total_price ?? $invoice->amount);
        $discount = (float) ($order?->discount_amount ?? 0);
        $days     = max(1, (int) ($order?->duration ?? 1));

        return [
            'invoice'      => $invoice,
            'order'        => $order,
            'requisites'   => $invoice->requisites ?? [],
            'subtotal'     => $subtotal,
            'discount'     => $discount,
            'pricePerDay'  => (float) ($order?->price_per_day ?? round($subtotal / $days, 2)),
        ];
    }
}
