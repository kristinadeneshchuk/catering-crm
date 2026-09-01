<?php

namespace App\Models;

use App\Support\Phone;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Клієнт кабінету.
 *
 * Пароля немає взагалі: вхід — одноразовий код на телефон. Пароль на такому
 * сайті заводять раз на рік і забувають до наступної оренди, а телефон клієнт
 * і так диктує менеджеру.
 */
class Client extends Authenticatable
{
    protected $guarded = [];

    protected $hidden = ['remember_token'];

    protected function casts(): array
    {
        return [
            'last_login_at' => 'datetime',
            'win_back_sent_at' => 'datetime',
            'marketing_opt_out' => 'bool',
        ];
    }

    /** Телефон завжди лежить у канонічному вигляді 380XXXXXXXXX. */
    protected function phone(): Attribute
    {
        return Attribute::set(fn (?string $value) => Phone::normalize($value) ?? $value);
    }

    public function getDisplayPhoneAttribute(): ?string
    {
        return Phone::format($this->phone);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class)->latest('id');
    }

    public function favourites(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'favourites')->withTimestamps();
    }

    /**
     * Підбирає броні, зроблені до реєстрації.
     *
     * Кабінет без історії замовлень нікому не потрібен, а клієнт майже завжди
     * спершу бронює і лише потім заходить.
     */
    public function claimBookings(): int
    {
        return Booking::whereNull('client_id')
            ->where('phone', Phone::format($this->phone))
            ->update(['client_id' => $this->id]);
    }
}
