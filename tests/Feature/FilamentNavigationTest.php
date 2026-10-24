<?php

namespace Tests\Feature;

use Filament\Facades\Filament;
use Tests\TestCase;

/**
 * Filament падає з 500 на ВСІЙ адмінці, якщо в групі меню з іконкою хоч один
 * пункт теж має іконку. 24.10.2026 так лягла CRM після перебудови кешу компонентів
 * (звіти курʼєрів у «Система»). Перевіряємо всі ресурси й сторінки панелі.
 */
class FilamentNavigationTest extends TestCase
{
    public function test_no_item_has_an_icon_inside_a_group_that_has_one(): void
    {
        $panel = Filament::getPanel('admin');
        $groupsWithIcon = collect($panel->getNavigationGroups())
            ->filter(fn ($g) => is_object($g) && filled($g->getIcon()))
            ->map(fn ($g) => $g->getLabel())->values()->all();

        $bad = [];
        foreach (array_merge($panel->getResources(), $panel->getPages()) as $class) {
            $ref = new \ReflectionClass($class);
            $props = $ref->getDefaultProperties();
            $group = $props['navigationGroup'] ?? null;
            $icon = $props['navigationIcon'] ?? null;
            if ($group && $icon && in_array($group, $groupsWithIcon, true)) $bad[] = "{$class} ({$group})";
        }

        $this->assertSame([], $bad, 'Іконка пункту в групі з іконкою — уся адмінка дасть 500');
    }
}
