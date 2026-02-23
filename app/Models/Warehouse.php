<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Warehouse extends Model
{
    protected $fillable = ['name'];

    public function stockDocuments()
    {
        return $this->hasMany(StockDocument::class);
    }
}