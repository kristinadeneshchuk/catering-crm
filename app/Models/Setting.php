<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    // Дозволяємо системі записувати дані у ці колонки
    protected $fillable = ['key', 'value'];
}