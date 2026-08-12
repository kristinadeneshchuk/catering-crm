<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    // Дозволяємо зберігати ці поля через адмінку
    protected $fillable = [
        'name',
        'slug',
        'logo',
        'color',
        'is_active',
        // Реквізити для рахунків — у кожного бренду свій ФОП і свій рахунок.
        'recipient_name',
        'iban',
        'tax_id',
        'bank_name',
        'mfo',
        'payment_purpose',
    ];
}