<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    // Список доступных ролей
    const ROLE_ADMIN = 'admin';
    const ROLE_MANAGER = 'manager';
    const ROLE_COOK = 'cook';

    protected $fillable = [
        'name',
        'email',
        'password',
        'role', // <--- Добавили это, чтобы можно было менять через админку
        'ui_prefs', // Персональні UI-налаштування (запам'ятані дати сторінок тощо)
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'ui_prefs' => 'array',
        ];
    }

    // Вспомогательные методы (пригодятся позже)
    public function isAdmin() { return $this->role === self::ROLE_ADMIN; }
    public function isManager() { return $this->role === self::ROLE_MANAGER; }
    public function isCook() { return $this->role === self::ROLE_COOK; }

    /**
     * Прочитати персональне UI-налаштування (dot-нотація: 'payroll.start').
     */
    public function uiPref(string $key, $default = null)
    {
        return data_get($this->ui_prefs, $key, $default);
    }

    /**
     * Зберегти персональне UI-налаштування (запам'ятані дати сторінок тощо).
     */
    public function setUiPref(string $key, $value): void
    {
        $prefs = $this->ui_prefs ?? [];
        data_set($prefs, $key, $value);
        $this->update(['ui_prefs' => $prefs]);
    }
}