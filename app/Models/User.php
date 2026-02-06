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
        ];
    }
    
    // Вспомогательные методы (пригодятся позже)
    public function isAdmin() { return $this->role === self::ROLE_ADMIN; }
    public function isManager() { return $this->role === self::ROLE_MANAGER; }
    public function isCook() { return $this->role === self::ROLE_COOK; }
}