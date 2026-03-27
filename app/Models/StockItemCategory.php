<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockItemCategory extends Model
{
    protected $fillable = ['name', 'icon', 'model_class', 'sort_order'];

    public function getDisplayNameAttribute(): string
    {
        return $this->icon . ' ' . $this->name;
    }
}
