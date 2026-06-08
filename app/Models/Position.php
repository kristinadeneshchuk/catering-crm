<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Position extends Model
{
    protected $fillable = [
        'key', 'name', 'color', 'payment_type',
        'monthly_working_days', 'split_by_brands', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'split_by_brands'      => 'boolean',
        'is_active'            => 'boolean',
        'monthly_working_days' => 'integer',
        'sort_order'           => 'integer',
    ];

    public function employees(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Employee::class, 'position', 'key');
    }

    public function isMonthly(): bool
    {
        return $this->payment_type === 'per_month';
    }
}
