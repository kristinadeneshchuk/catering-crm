<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Client;
use App\Support\Phone;

/**
 * Знижка постійного клієнта.
 *
 * Рахується від кількості **закритих** оренд: підтверджена й видана ще можуть
 * скасуватися, а обіцяти знижку за замовлення, яке ще не відбулося, означає
 * потім її забирати.
 *
 * Знижка діє тільки на оренду. Витратники йдуть з мінімальною націнкою,
 * доставка — це гроші перевізнику, застава взагалі не дохід: знижувати їх
 * означало б платити клієнту за оренду з власної кишені.
 *
 * Клієнта шукаємо за телефоном, а не за сесією: постійний клієнт заслуговує на
 * свою знижку і тоді, коли забув увійти в кабінет.
 */
class Loyalty
{
    /** Скільки відсотків належить цьому номеру телефону. */
    public function percentForPhone(?string $phone): int
    {
        $normalized = Phone::normalize($phone);

        if (! $normalized) {
            return 0;
        }

        return $this->percentFor(Client::where('phone', $normalized)->first());
    }

    /** Скільки відсотків належить клієнту. */
    public function percentFor(?Client $client): int
    {
        if (! $client) {
            return 0;
        }

        // Ручна знижка від менеджера перебиває сходинку: він домовлявся з
        // клієнтом особисто і знає більше, ніж лічильник оренд.
        $percent = $client->discount_percent ?? $this->levelPercent($this->completedRentals($client));

        return min($percent, (int) config('loyalty.max_percent', 10));
    }

    /** Назва рівня — те, що клієнт бачить у кабінеті. */
    public function titleFor(?Client $client): ?string
    {
        if (! $client) {
            return null;
        }

        $completed = $this->completedRentals($client);
        $title = null;

        foreach ($this->levels() as $from => $level) {
            if ($completed >= $from) {
                $title = $level['title'];
            }
        }

        return $title;
    }

    /** Скільки оренд лишилось до наступної сходинки; null — вона остання. */
    public function rentalsToNextLevel(?Client $client): ?int
    {
        if (! $client) {
            return null;
        }

        $completed = $this->completedRentals($client);

        foreach ($this->levels() as $from => $level) {
            if ($completed < $from) {
                return $from - $completed;
            }
        }

        return null;
    }

    /** Сума знижки на оренду. Округлення — на користь клієнта. */
    public function amount(int $rentTotal, int $percent): int
    {
        return (int) floor($rentTotal * $percent / 100);
    }

    public function completedRentals(Client $client): int
    {
        return Booking::where('client_id', $client->id)->where('status', 'closed')->count();
    }

    /** @return array<int, array{percent: int, title: string}> */
    public function levels(): array
    {
        $levels = config('loyalty.levels', []);
        ksort($levels);

        return $levels;
    }

    private function levelPercent(int $completed): int
    {
        $percent = 0;

        foreach ($this->levels() as $from => $level) {
            if ($completed >= $from) {
                $percent = $level['percent'];
            }
        }

        return $percent;
    }
}
