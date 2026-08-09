<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class UnavailableDate extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    /**
     * Пишемо рівно «Y-m-d», без часу.
     *
     * Eloquent за замовчуванням клав сюди «2026-08-14 00:00:00», і тоді
     * firstOrCreate(['date' => '2026-08-14']) не знаходив наявний рядок,
     * намагався вставити дубль і падав на унікальному індексі — тобто
     * бронювання на дату, яка вже чимось зайнята, віддавало 500.
     */
    protected function date(): Attribute
    {
        return Attribute::set(fn ($value) => Carbon::parse($value)->toDateString());
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
