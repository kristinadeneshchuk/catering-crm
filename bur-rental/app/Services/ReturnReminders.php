<?php

namespace App\Services;

use App\Models\Booking;
use App\Services\Messaging\Sms;
use Illuminate\Support\Collection;

/**
 * Нагадування за добу до повернення.
 *
 * Найдорожча претензія в оренді — «мене не попередили». Клієнт забув дату,
 * приїхав на день пізніше, отримав рахунок за прострочення за базовим тарифом
 * і пішов писати відгук. SMS напередодні знімає і претензію, і половину
 * прострочень: техніка повертається вчасно й одразу їде наступному клієнту.
 *
 * Надсилається один раз на бронь — за це відповідає `return_reminded_at`.
 */
class ReturnReminders
{
    /** Межа однієї SMS: далі оператор рахує повідомлення як два. */
    public const SMS_LIMIT = 160;

    public function __construct(
        private readonly Sms $sms,
        private readonly ManagerAlerts $alerts,
    ) {}

    /**
     * Броні, які завтра мають повернутися і ще не отримали нагадування.
     *
     * Тільки видані й підтверджені: за нову бронь, яку клієнт не забрав,
     * нагадувати про повернення безглуздо.
     *
     * @return Collection<int, Booking>
     */
    public function due(): Collection
    {
        return Booking::query()
            ->with(['items', 'branch.city'])
            ->whereIn('status', ['confirmed', 'issued'])
            ->whereNull('return_reminded_at')
            ->whereDate('date_to', today()->addDay())
            ->orderBy('id')
            ->get();
    }

    /** Розсилає нагадування і повертає, скільком клієнтам вони пішли. */
    public function send(): int
    {
        $bookings = $this->due();

        foreach ($bookings as $booking) {
            $this->sms->send($booking->phone, $this->text($booking));

            // Позначаємо одразу після відправки: якщо крон упаде на наступній
            // броні, цей клієнт другої SMS не отримає.
            $booking->forceFill(['return_reminded_at' => now()])->save();
        }

        // Менеджеру — одним списком, щоб зранку знати, кого чекати і кому
        // дзвонити, якщо не приїхали.
        if ($bookings->isNotEmpty()) {
            $this->alerts->returnsTomorrow($bookings);
        }

        return $bookings->count();
    }

    /**
     * Текст SMS.
     *
     * Тут тільки те, без чого не обійтись: коли, що, куди везти і кому дзвонити.
     * Усе разом мусить лягти в одну SMS.
     */
    public function text(Booking $booking): string
    {
        $what = $booking->items->count() === 1
            ? $booking->items->first()->title
            : $booking->items->count().' поз.';

        $where = $booking->fulfilment === 'delivery'
            ? 'заберемо за адресою'
            : 'здати на '.($booking->branch?->name ?? 'філію');

        $compose = fn (string $what) => sprintf(
            'БУР: завтра %s повертати %s (%s), %s. Продовжити — %s',
            $booking->date_to->format('d.m'),
            $what,
            $booking->number,
            $where,
            // Телефон свій у кожному місті — той самий, що в хедері сайту.
            $booking->branch?->city?->phone ?? ''
        );

        $text = $compose($what);

        // Довга назва моделі здатна вигнати повідомлення в другу SMS. Тоді
        // назва не потрібна: номер броні клієнт однаково знайде в кабінеті.
        return mb_strlen($text) <= self::SMS_LIMIT
            ? $text
            : $compose($booking->items->count().' поз.');
    }
}
