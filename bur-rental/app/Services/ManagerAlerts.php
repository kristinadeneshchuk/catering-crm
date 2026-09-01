<?php

namespace App\Services;

use App\Jobs\NotifyManagerInTelegram;
use App\Models\Booking;
use App\Models\Client;
use App\Models\Lead;
use Illuminate\Support\Collection;

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

    /**
     * Список повернень на завтра — одним повідомленням увечері.
     *
     * Менеджеру потрібен не сигнал по кожній броні, а перелік: скільки одиниць
     * заходить, хто саме і кому дзвонити, якщо не приїхали.
     *
     * @param  Collection<int, Booking>  $bookings
     */
    public function returnsTomorrow(Collection $bookings): void
    {
        $rows = $bookings
            ->map(fn (Booking $b) => "• {$b->number} · ".e($b->company ?: $b->name ?: '—').
                " · {$b->phone}\n   ".e($b->items->pluck('title')->join(', ')))
            ->join("\n");

        $this->send(
            '📦 <b>Завтра повертають ('.$bookings->count()." шт.)</b>\n\n".
            $rows."\n\n".
            "Клієнтам нагадування вже пішло.\n".
            url('/admin/bookings')
        );
    }

    /**
     * Звіт про розсилку на повернення.
     *
     * Менеджеру це не «до відома»: людина, якій щойно написали, може
     * передзвонити протягом години, і краще, щоб він знав, з чого розмова.
     *
     * @param  Collection<int, Client>  $clients
     */
    public function winBackSent(Collection $clients): void
    {
        $rows = $clients->take(15)
            ->map(fn (Client $c) => "• {$c->display_phone} · ".e($c->company ?: $c->name ?: '—'))
            ->join("\n");

        $more = $clients->count() > 15 ? "\n… і ще ".($clients->count() - 15) : '';

        $this->send(
            '📣 <b>Нагадали про себе ('.$clients->count()." шт.)</b>\n\n".
            $rows.$more."\n\n".
            "Це клієнти, які давно не орендували. Можуть передзвонити — знижка постійного в них уже діє.\n".
            url('/admin/clients')
        );
    }

    private function send(string $text): void
    {
        // Диспатчимо завжди: перевірка токена всередині джоби, щоб контролери
        // не знали про налаштування Telegram узагалі.
        NotifyManagerInTelegram::dispatch($text);
    }
}
