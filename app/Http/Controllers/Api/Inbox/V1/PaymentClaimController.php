<?php

namespace App\Http\Controllers\Api\Inbox\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\PaymentClaim;
use App\Services\Payments\PaymentClaimService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Заяви про оплату від агента продажів.
 *
 * Агент фіксує, що клієнт каже «оплатив» чи «передав готівкою курʼєру» — і
 * більше нічого. Ендпоінту, який ставить оплату, в агента немає й не буде:
 * гроші в CRM зʼявляються лише після підтвердження менеджером.
 */
class PaymentClaimController extends Controller
{
    public function __construct(private PaymentClaimService $claims)
    {
    }

    public function store(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            // Агенту доступні лише заяви клієнта. Готівку «зі звіту курʼєра» і
            // «виставлено рахунок» система створює сама.
            'source'     => ['required', 'in:'.PaymentClaim::SOURCE_CLIENT_TRANSFER.','.PaymentClaim::SOURCE_CLIENT_CASH],
            'amount'     => ['required', 'numeric', 'min:0.01', 'max:1000000'],
            'comment'    => ['nullable', 'string', 'max:1000'],
            // Скрін платіжки. Лежить у приватному сховищі: там банківські дані.
            'attachment' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,heic,pdf', 'max:10240'],
        ]);

        $path = $request->hasFile('attachment')
            ? $request->file('attachment')->store("payment-claims/{$order->id}", 'local')
            : null;

        $claim = $this->claims->create($order, [
            'source'           => $data['source'],
            'amount'           => $data['amount'],
            'reported_by_type' => PaymentClaim::REPORTED_BY_AGENT,
            'comment'          => $data['comment'] ?? null,
            'attachment_path'  => $path,
        ]);

        return response()->json([
            'claim'   => $this->claims->claimPayload($claim),
            'payment' => $this->claims->orderPaymentState($order->fresh()),
        ], 201);
    }

    /**
     * Стан оплати одного замовлення — щоб агент відповів клієнту без здогадок:
     * «менеджер підтвердить сьогодні», «оплату отримали» чи «надішліть квитанцію».
     */
    public function show(Order $order): JsonResponse
    {
        return response()->json([
            'order_id' => $order->id,
            'payment'  => $this->claims->orderPaymentState($order),
        ]);
    }
}
