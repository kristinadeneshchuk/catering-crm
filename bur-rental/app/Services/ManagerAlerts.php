<?php

namespace App\Services;

use App\Jobs\NotifyManagerInTelegram;
use App\Models\Booking;
use App\Models\Lead;

/**
 * Тексти сповіщень менеджеру. Формат один: що сталося, хто, скільки,
 * і посилання в адмінку — щоб з телефона одним тапом потрапити в бронь.
 */
class ManagerAlerts
{
    public function bookingCreated(Booking $booking): void
    {
        $items = $booking->items
            ->map(fn ($item) => "• {$item->title} × {$item->qty}")
            ->join("\n");

        $client = e($booking->company ?: $booking->name ?: '—');

        $fulfilment = $booking->fulfilment === 'delivery'
            ? '🚚 Доставка: '.e($booking->address ?? '')
            : '🏠 Самовивіз: '.e($booking->branch?->name ?? '');

        $this->send(
            "🟢 <b>Нова бронь {$booking->number}</b>\n\n".
            "{$client}\n📞 {$booking->phone}\n\n".
            e($items)."\n\n".
            "📅 {$booking->date_from->format('d.m')} — {$booking->date_to->format('d.m')} ({$booking->days} дн.)\n".
            "{$fulfilment}\n".
            '💰 До сплати: '.number_format($booking->payable, 0, ',', ' ')." ₴\n".
            '   з них застава '.number_format($booking->deposit_total, 0, ',', ' ')." ₴\n\n".
            url('/admin/bookings')
        );
    }

    public function leadCreated(Lead $lead): void
    {
        $kind = match ($lead->kind) {
            'b2b' => '💼 Запит КП від юрособи',
            'notify' => '🔔 Чекає, коли звільниться',
            'contact' => '✉️ Питання з контактів',
            default => '📞 Передзвоніть мені',
        };

        $who = e($lead->company ?: $lead->name ?: '—');

        $this->send(
            "{$kind}\n\n".
            "{$who}\n".
            ($lead->phone ? "📞 {$lead->phone}\n" : '').
            ($lead->message ? "\n".e($lead->message)."\n" : '').
            ($lead->context ? "\nЗвідки: {$lead->context}\n" : '').
            "\n".url('/admin/leads')
        );
    }

    private function send(string $text): void
    {
        // Диспатчимо завжди: перевірка токена всередині джоби, щоб контролери
        // не знали про налаштування Telegram узагалі.
        NotifyManagerInTelegram::dispatch($text);
    }
}
