<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets;
use Filament\Navigation\NavigationGroup; // Імпортуємо NavigationGroup для керування групами
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function register(): void
    {
        parent::register();

        FilamentAsset::register([
            Js::make('kitchen-echo', \Illuminate\Support\Facades\Vite::asset('resources/js/app.js')),
        ]);
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->brandName(env('APP_NAME', 'CRM'))
            ->favicon(asset(config('app.favicon', 'images/favicon.svg')))
            ->login()
            ->colors([
                'primary' => Color::Amber,
            ])
// НАЛАШТУВАННЯ ГРУП НАВІГАЦІЇ
            ->navigationGroups([
                // Група "Виробництво" — завжди відкрита для цеху та пакування
                NavigationGroup::make('Виробництво')
                    ->icon('heroicon-o-building-office-2'),

                // 🔥 ДОДАЄМО СКЛАД І РОБИМО ЙОГО ЗГОРНУТИМ
                NavigationGroup::make('Склад')
                    ->icon('heroicon-o-archive-box') // Можеш змінити іконку на свою
                    ->collapsed(), 

                // "Довідник" — за замовчуванням згорнутий
                NavigationGroup::make('Довідник')
                    ->icon('heroicon-o-book-open')
                    ->collapsed(), 

                // "Система" — за замовчуванням згорнута для безпеки
                NavigationGroup::make('Система')
                    ->icon('heroicon-o-cog-8-tooth')
                    ->collapsed(),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                Widgets\AccountWidget::class,
                \App\Filament\Widgets\PublicMenuLink::class,
                \App\Filament\Widgets\ProductionLoadList::class,
                \App\Filament\Widgets\DebtsList::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->renderHook('panels::head.end', fn () => new \Illuminate\Support\HtmlString(
                '<link rel="stylesheet" href="/css/leaflet/leaflet.css">'
            ))
            ->renderHook('panels::body.end', fn () => new \Illuminate\Support\HtmlString(
                '<script src="/js/leaflet/leaflet.js"></script>
<script>
(function () {
    function initLeafletMaps() {
        if (!window.L) return;
        document.querySelectorAll("[data-leaflet-cfg]:not([data-leaflet-ready])").forEach(function (el) {
            el.setAttribute("data-leaflet-ready", "1");
            var cfg;
            try { cfg = JSON.parse(el.getAttribute("data-leaflet-cfg")); } catch(e) { return; }

            delete L.Icon.Default.prototype._getIconUrl;
            L.Icon.Default.mergeOptions({
                iconUrl:       "/css/leaflet/images/marker-icon.png",
                iconRetinaUrl: "/css/leaflet/images/marker-icon-2x.png",
                shadowUrl:     "/css/leaflet/images/marker-shadow.png",
            });

            var map    = L.map(el).setView(cfg.center, cfg.zoom);
            var marker = null;

            L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
                attribution: "\u00a9 OpenStreetMap contributors"
            }).addTo(map);

            if (cfg.hasCoords) {
                marker = L.marker(cfg.center, { draggable: true }).addTo(map);
                marker.on("dragend", function (e) {
                    onCoords(e.target.getLatLng().lat, e.target.getLatLng().lng);
                });
            }

            map.on("click", function (e) { onCoords(e.latlng.lat, e.latlng.lng); });

            setTimeout(function () { map.invalidateSize(); }, 200);
            setTimeout(function () { map.invalidateSize(); }, 600);

            function onCoords(lat, lng) {
                if (marker) { marker.setLatLng([lat, lng]); }
                else {
                    marker = L.marker([lat, lng], { draggable: true }).addTo(map);
                    marker.on("dragend", function (e) {
                        onCoords(e.target.getLatLng().lat, e.target.getLatLng().lng);
                    });
                }
                // Глобальний Livewire event — працює навіть якщо модалка телепортована в body
                Livewire.dispatch("map-coords-updated", { lat: lat, lng: lng });
            }
        });
    }

    document.addEventListener("DOMContentLoaded", function () {
        initLeafletMaps();
        setInterval(initLeafletMaps, 500);
    });

    document.addEventListener("livewire:navigated", initLeafletMaps);
    document.addEventListener("livewire:update", initLeafletMaps);
})();
</script>'
            ));
    }
}