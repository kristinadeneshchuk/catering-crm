<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Client;
use App\Services\Messaging\Sms;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Повернення клієнта після паузи.
 *
 * Найдешевший клієнт — той, який уже орендував: він знає, де склад, як
 * оформити і що ми не обманюємо з наявністю. Але в прокаті людина зникає
 * природно: зробила ремонт і три роки нічого не орендує. Одне доречне
 * повідомлення через квартал повертає частину таких без жодних витрат на
 * рекламу.
 *
 * Межа між нагадуванням і спамом тут тонка, тому правил більше, ніж коду:
 * пишемо не раніше ніж через `after_days`, не частіше ніж раз на
 * `cooldown_days`, не тим, у кого зараз щось на руках, і ніколи тим, хто
 * попросив не писати.
 */
class WinBack
{
    /** Межа однієї SMS — та сама, що й у нагадуваннях про повернення. */
    public const SMS_LIMIT = 160;

    public function __construct(
        private readonly Sms $sms,
        private readonly Loyalty $loyalty,
        private readonly ManagerAlerts $alerts,
    ) {}

    /**
     * Кого варто повернути.
     *
     * @return Collection<int, Client>
     */
    public function due(): Collection
    {
        $silentSince = now()->subDays((int) config('winback.after_days'));
        $cooldown = now()->subDays((int) config('winback.cooldown_days'));

        return Client::query()
            ->where('marketing_opt_out', false)
            ->where(fn (Builder $q) => $q->whereNull('win_back_sent_at')->orWhere('win_back_sent_at', '<', $cooldown))
            // Хоч одна завершена оренда: інакше це не повернення, а холодна
            // розсилка людині, яка нічого в нас не брала.
            ->whereHas('bookings', fn (Builder $b) => $b->where('status', 'closed'))
            // Нічого активного: писати «давно не бачились» тому, у кого зараз
            // наш перфоратор на руках, — найкращий спосіб виглядати безглуздо.
            ->whereDoesntHave('bookings', fn (Builder $b) => $b->whereIn('status', ['new', 'confirmed', 'issued']))
            ->whereDoesntHave('bookings', fn (Builder $b) => $b->where('date_to', '>=', $silentSince))
            ->withMax('bookings as last_rent', 'date_to')
            ->orderBy('last_rent')
            ->take((int) config('winback.batch'))
            ->get();
    }

    /** Розсилає і повертає, скільком клієнтам пішло. */
    public function send(): int
    {
        $clients = $this->due();

        foreach ($clients as $client) {
            $this->sms->send($client->phone, $this->text($client));

            $client->forceFill(['win_back_sent_at' => now()])->save();
        }

        if ($clients->isNotEmpty()) {
            $this->alerts->winBackSent($clients);
        }

        return $clients->count();
    }

    /**
     * Текст.
     *
     * Ніяких «ми скучили» і вигаданих акцій. Одна корисна річ (знижка, яку
     * людина справді заробила, або факт, що наявність видно онлайн) і спосіб
     * відписатися — без нього це спам, і за законом теж.
     *
     * ВАЖЛИВО при підключенні SMS-шлюзу: вхідне «СТОП» мусить проставляти
     * `clients.marketing_opt_out`. Зараз відписатися можна в кабінеті або
     * через менеджера, але текст обіцяє відповідь — і ця обіцянка має
     * працювати з першого ж бойового прогону.
     */
    public function text(Client $client): string
    {
        $percent = $this->loyalty->percentFor($client);

        $offer = $percent > 0
            ? "ваша знижка постійного -{$percent}% діє"
            : 'наявність по датах видно на сайті';

        return sprintf(
            'БУР: давно не бачились. Інструмент в оренду, %s. %s. Відмовитись від повідомлень - відповідь СТОП',
            $offer,
            rtrim(config('app.url', 'bur.ua'), '/'),
        );
    }

    /** Скільки клієнтів у черзі на повернення — для звіту менеджеру. */
    public function pending(): int
    {
        return $this->due()->count();
    }

    /** Останні оренди тих, кому пишемо: менеджеру корисно знати, хто це. */
    public function lastRent(Client $client): ?string
    {
        return Booking::where('client_id', $client->id)
            ->where('status', 'closed')
            ->max('date_to');
    }
}
