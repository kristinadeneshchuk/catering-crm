<?php

namespace App\Traits;

/**
 * Дозволяє доступ ролям admin, manager і cook (кухар/шеф-кухар).
 *
 * Використовується на «кухонних» розділах, якими шеф-кухар керує сам:
 * техкарти/страви, склад (документи, накладні, списання), інвентаризації,
 * довідники інгредієнтів/алергенів/постачальників/складів.
 *
 * Усе чутливе (ЗП, табелі, зміни, співробітники, клієнти, замовлення,
 * транзакції, налаштування) лишається під RestrictCookAccess / admin-only,
 * тож кухар туди не потрапляє.
 */
trait AllowCookAccess
{
    public static function canAccess(): bool
    {
        return in_array(auth()->user()->role, ['admin', 'manager', 'cook'], true);
    }

    public static function canViewAny(): bool
    {
        return in_array(auth()->user()->role, ['admin', 'manager', 'cook'], true);
    }
}
