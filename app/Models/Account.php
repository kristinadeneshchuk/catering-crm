<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'type',
        'balance',
        'is_default',
        'mono_token',
        'mono_account_id',
        'mono_iban',
        'mono_synced_at',
    ];

    // Токен банку ніколи не віддаємо у формах, JSON і логах.
    protected $hidden = ['mono_token'];

    protected $casts = [
        'is_default' => 'boolean',
        'balance' => 'decimal:2',
        'mono_token' => 'encrypted',
        'mono_synced_at' => 'datetime',
    ];

    public function bankOperations(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(BankOperation::class);
    }

    public function hasMonoToken(): bool
    {
        return filled($this->getRawOriginal('mono_token'));
    }

    /** «••••1234» — останні 4 символи токена, щоб упізнати, який стоїть. */
    public function maskedMonoToken(): ?string
    {
        if (!$this->hasMonoToken()) return null;

        return '••••' . mb_substr((string) $this->mono_token, -4);
    }
}