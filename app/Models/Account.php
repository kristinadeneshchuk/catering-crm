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
        'mono_account_type',
        'bank_only',
    ];

    /** Типи рахунків у monobank, які можна підключити. */
    public const MONO_TYPES = [
        'fop'   => 'ФОП (гривня)',
        'white' => 'Біла картка',
        'black' => 'Чорна картка',
    ];

    /**
     * Рахунки «лише для банку» не існують для решти CRM: ні у виборі
     * рахунку для оплат/ЗП/накладних, ні в касі дня. Явно їх бачать лише
     * «Банк», синхронізація і довідник рахунків супер адміна.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('payable', fn ($q) => $q->where($q->qualifyColumn('bank_only'), false));
    }

    /** Усі рахунки, включно з «лише для банку». */
    public static function withBankOnly(): \Illuminate\Database\Eloquent\Builder
    {
        return static::withoutGlobalScope('payable');
    }

    // Токен банку ніколи не віддаємо у формах, JSON і логах.
    protected $hidden = ['mono_token'];

    protected $casts = [
        'is_default' => 'boolean',
        'balance' => 'decimal:2',
        'mono_token' => 'encrypted',
        'mono_synced_at' => 'datetime',
        'bank_only' => 'boolean',
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