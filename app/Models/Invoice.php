<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    protected $fillable = [
        'number', 'sequence', 'order_id', 'client_id', 'project',
        'issued_on', 'amount', 'purpose', 'requisites',
        'token', 'sent_at', 'created_by',
    ];

    protected $casts = [
        'issued_on'  => 'date',
        'amount'     => 'decimal:2',
        'requisites' => 'array',
        'sent_at'    => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function projectData(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project', 'slug');
    }

    /** Публічне посилання на PDF — його відправляють клієнту. */
    public function pdfUrl(): string
    {
        return url("/invoices/{$this->token}.pdf");
    }

    /**
     * Текст із реквізитами для месенджера. Клієнти часто платять з телефону і
     * копіюють IBAN руками, тому кожен реквізит — окремим рядком.
     */
    public function requisitesText(): string
    {
        $r = $this->requisites ?? [];

        return collect([
            "Рахунок №{$this->number} від ".$this->issued_on->format('d.m.Y'),
            'Сума: '.number_format((float) $this->amount, 2, '.', ' ').' грн',
            '',
            'Отримувач: '.($r['recipient_name'] ?? '—'),
            'IBAN: '.($r['iban'] ?? '—'),
            'ЄДРПОУ/ІПН: '.($r['tax_id'] ?? '—'),
            'Банк: '.($r['bank_name'] ?? '—'),
            $r['mfo'] ?? null ? 'МФО: '.$r['mfo'] : null,
            '',
            'Призначення платежу:',
            $this->purpose,
        ])->filter(fn ($line) => $line !== null)->implode("\n");
    }
}
