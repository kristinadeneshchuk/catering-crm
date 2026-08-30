<?php

namespace App\Models;

use App\Support\Phone;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Booking extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['date_from' => 'date', 'date_to' => 'date'];
    }

    /**
     * Телефон зводиться до одного вигляду при збереженні.
     *
     * Від цього залежить кабінет: клієнт входить за номером, і його броні
     * знаходяться простим порівнянням рядків. «0672458080» і «+380 67 245 80 80»
     * мусять лягти в базу однаково, інакше історія замовлень буде порожня.
     */
    protected function phone(): Attribute
    {
        return Attribute::set(fn (?string $value) => Phone::format($value));
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function getRouteKeyName(): string
    {
        return 'number';
    }

    public function items(): HasMany
    {
        return $this->hasMany(BookingItem::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function deliveryZone(): BelongsTo
    {
        return $this->belongsTo(DeliveryZone::class);
    }

    /**
     * До сплати зараз: оренда + витратники + доставка + застава, мінус знижка.
     *
     * `rent_total` лишається повною сумою оренди, а знижка стоїть окремим
     * рядком — і клієнту видно, скільки він зекономив, і в звітності видно,
     * скільки ми віддали.
     */
    public function getPayableAttribute(): int
    {
        return $this->rent_total + $this->extras_total + $this->delivery_total
            + $this->deposit_total - $this->discount_total;
    }

    public function getDaysAttribute(): int
    {
        return $this->date_from->diffInDays($this->date_to) + 1;
    }

    /** @return array<string, string> */
    public static function statuses(): array
    {
        return [
            'new' => 'Нова',
            'confirmed' => 'Підтверджена',
            'issued' => 'На руках',
            'closed' => 'Закрита',
            'cancelled' => 'Скасована',
        ];
    }

    public function getStatusLabelAttribute(): string
    {
        return self::statuses()[$this->status] ?? $this->status;
    }

    /**
     * Скільки днів лишилось до повернення; від'ємне — прострочення.
     *
     * Головне число кабінету: за ним клієнт розуміє, чи треба щось робити
     * сьогодні. Прострочення рахується за базовим тарифом, тому воно дорожче
     * за звичайний день оренди — про це видно з картки.
     */
    public function getReturnsInAttribute(): int
    {
        return (int) now()->startOfDay()->diffInDays($this->date_to->startOfDay(), false);
    }
}
