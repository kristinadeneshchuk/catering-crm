<?php

namespace App\Http\Controllers\Api\Inbox\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Inbox\InvoiceService;
use Illuminate\Http\JsonResponse;

/**
 * Виставлення рахунку із зовнішньої системи листування.
 *
 * Повторний виклик віддає наявний рахунок, а не новий номер: клієнт має
 * бачити один документ на одне замовлення.
 */
class InvoiceController extends Controller
{
    public function store(Order $order, InvoiceService $invoices): JsonResponse
    {
        $invoice = $invoices->forOrder($order);
        $r = $invoice->requisites ?? [];

        return response()->json([
            'invoice' => [
                'id'             => $invoice->id,
                'number'         => $invoice->number,
                'date'           => $invoice->issued_on->toDateString(),
                'amount'         => (float) $invoice->amount,
                'purpose'        => $invoice->purpose,
                'recipient_name' => $r['recipient_name'] ?? null,
                'iban'           => $r['iban'] ?? null,
                'tax_id'         => $r['tax_id'] ?? null,
                'bank_name'      => $r['bank_name'] ?? null,
                'mfo'            => $r['mfo'] ?? null,
                'pdf_url'        => $invoice->pdfUrl(),
                'text'           => $invoice->requisitesText(),
            ],
        ], 201);
    }
}
