<?php

namespace App\Http\Controllers\Api\Inbox\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\PaymentClaim;
use App\Services\Inbox\InvoiceService;
use App\Services\Payments\PaymentClaimService;
use Illuminate\Http\JsonResponse;

/**
 * Виставлення рахунку із зовнішньої системи листування.
 *
 * Повторний виклик віддає наявний рахунок, а не новий номер: клієнт має
 * бачити один документ на одне замовлення.
 */
class InvoiceController extends Controller
{
    /** На кого можна виставити рахунок: рахунки (ФОП) із заповненими реквізитами. */
    public function recipients(): JsonResponse
    {
        $list = \App\Models\Account::withBankOnly()->orderBy('id')->get()
            ->filter(fn ($a) => $a->canInvoice())
            ->map(fn ($a) => ['account_id' => $a->id, 'name' => $a->name] + $a->invoiceRequisites())
            ->values();

        return response()->json(['recipients' => $list]);
    }

    public function store(\Illuminate\Http\Request $request, Order $order, InvoiceService $invoices, PaymentClaimService $claims): JsonResponse
    {
        $data = $request->validate(['account_id' => ['nullable', 'integer']]);
        $account = ! empty($data['account_id']) ? \App\Models\Account::withBankOnly()->find($data['account_id']) : null;
        if (! empty($data['account_id']) && ! $account) {
            throw \Illuminate\Validation\ValidationException::withMessages(['account_id' => 'Такого рахунку немає.']);
        }

        $invoice = $invoices->forOrder($order, false, $account);
        $r = $invoice->requisites ?? [];

        // Рахунок агент виставив — отже, чекаємо переказ. Менеджер побачить це в
        // «Чекають підтвердження». Повторний виклик другої заяви не створює.
        $claims->recordInvoice($invoice, PaymentClaim::REPORTED_BY_AGENT);

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
