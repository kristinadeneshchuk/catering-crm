<?php

namespace App\Traits;

/**
 * Restricts access to admin and manager roles only.
 * Cooks are blocked from pages/resources using this trait.
 */
trait RestrictCookAccess
{
    public static function canAccess(): bool
    {
        return in_array(auth()->user()->role, ['admin', 'manager'], true);
    }
}
