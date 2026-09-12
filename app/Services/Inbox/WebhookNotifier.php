<?php

namespace App\Services\Inbox;

use App\Jobs\SendInboxWebhook;
use App\Models\Order;
use Illuminate\Support\Str;

/**
 * Події CRM → Telegram Inbox.
 *
 * Шлемо тільки по замовленнях, оформлених через Inbox (orders.source). Інакше
 * нічний RecalculateClientBalances вистрелив би пачкою подій по всій історії
 * замовлень, яких зовнішня система ніколи не бачила.
 */
class WebhookNotifier
{
    public const SOURCE_INBOX = 'telegram_inbox';

    /**
     * Замовлення стало оплаченим або оплата відкотилась.
     *
     * Викликається з Client::recalculateOrderPaymentStatus() — це єдина точка,
     * де is_paid реально змінюється. Модельні події тут не допомогли б:
     * статус ставиться через updateQuietly і обсервери не спрацьовують.
     */
    public function paymentStatusChanged(Order $order, bool $paid): void
    {
        if (! $this->enabled($order)) {
            return;
        }

        SendInboxWebhook::dispatch(
            $paid ? 'order.payment_received' : 'order.payment_reverted',
            'evt_'.Str::ulid(),
            [
                'order_id'       => $order->id,
                'order_number'   => (string) $order->id,
                'client_id'      => $order->client_id,
                'payment_status' => $paid ? 'paid' : 'unpaid',
                'is_paid'        => $paid,
                'amount'         => (float) ($order->final_price ?? $order->total_price),
            ],
            now()->toIso8601String(),
        );
    }

    /**
     * Менеджер опрацював заяву про оплату — агент має знати, що сказати клієнту.
     *
     * confirmed → «Оплату отримали, дякую!»;
     * rejected  → «Не можемо знайти оплату… надішліть квитанцію».
     *
     * Окремо від order.payment_received: заява може бути підтверджена, а
     * замовлення лишитись неоплаченим (частина суми), і навпаки.
     */
    public function claimResolved(\App\Models\PaymentClaim $claim): void
    {
        $order = $claim->order;

        if (! $order || ! $this->enabled($order)) {
            return;
        }

        $event = match ($claim->status) {
            \App\Models\PaymentClaim::STATUS_CONFIRMED => 'payment_claim.confirmed',
            \App\Models\PaymentClaim::STATUS_REJECTED  => 'payment_claim.rejected',
            default                                     => null,
        };

        if ($event === null) {
            return;
        }

        SendInboxWebhook::dispatch(
            $event,
            'evt_'.Str::ulid(),
            [
                'claim_id'      => $claim->id,
                'order_id'      => $order->id,
                'client_id'     => $order->client_id,
                'source'        => $claim->source,
                'amount'        => (float) $claim->amount,
                'confirmed_amount' => $claim->transaction ? (float) $claim->transaction->amount : null,
                'status'        => $claim->status,
                'reject_reason' => $claim->reject_reason,
                'order_is_paid' => (bool) $order->fresh()->is_paid,
            ],
            now()->toIso8601String(),
        );
    }

    protected function enabled(Order $order): bool
    {
        return $order->source === self::SOURCE_INBOX
            && (string) config('services.inbox.webhook_url') !== '';
    }
}
