<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Подія з вебхука Inbox: лише ідентифікатори, без тексту повідомлення. */
class InboxEvent extends Model
{
    protected $guarded = [];

    protected $casts = ['occurred_at' => 'datetime'];
}
