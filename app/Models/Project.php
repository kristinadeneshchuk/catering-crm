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
        'is_active'
    ];
}