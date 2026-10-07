<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Документ з групи «Бухгалтерія» (фото, PDF або альбом).
 *
 * Шлях: new → (ШІ визначив тип) →
 *   invoice:  proposed → added | rejected;  або duplicate
 *   payment:  proposed → paid (накладну позначено оплаченою) | needs_owner → recorded | skipped
 *   other:    ignored
 *   failed — не вдалося зчитати.
 */
class AccountingItem extends Model
{
    public const KIND_INVOICE = 'invoice';
    public const KIND_PAYMENT = 'payment';
    public const KIND_OTHER   = 'other';

    protected $guarded = [];

    protected $casts = [
        'files'      => 'array',
        'extracted'  => 'array',
        'decided_at' => 'datetime',
    ];

    public function stockDocument()
    {
        return $this->belongsTo(StockDocument::class);
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    /** @return array<int, string> */
    public function paths(): array
    {
        return array_values(array_map(fn ($f) => (string) $f['path'], $this->files ?? []));
    }
}
