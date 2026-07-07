<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;

/**
 * Картка на дашборді з постійним посиланням «Меню на сьогодні» —
 * менеджер копіює або відкриває його, щоб надіслати клієнту під час консультації.
 */
class PublicMenuLink extends Widget
{
    protected static string $view = 'filament.widgets.public-menu-link';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = -3;

    public static function canView(): bool
    {
        return in_array(auth()->user()?->role, ['admin', 'manager'], true);
    }

    public function getUrl(): string
    {
        return route('menu.today');
    }
}
