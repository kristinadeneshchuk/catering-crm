<?php

namespace App\Filament\Pages;

use App\Models\KitchenNotification;
use Filament\Actions\Action;
use Filament\Pages\Page;

class KitchenNotifications extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-bell';
    protected static ?string $navigationLabel = 'Сповіщення';
    protected static ?string $title = 'Сповіщення кухні';
    protected static ?string $slug = 'kitchen-notifications';
    protected static ?int $navigationSort = 5;

    protected static string $view = 'filament.pages.kitchen-notifications';

    // Only show in nav for cook role
    public static function shouldRegisterNavigation(): bool
    {
        return auth()->check() && auth()->user()->isCook();
    }

    public static function canAccess(): bool
    {
        return auth()->check() && (auth()->user()->isCook() || auth()->user()->isAdmin());
    }

    public function getViewData(): array
    {
        // Прочитані — лише останні 50 (більше сторінка й не показувала): уся історія за місяці не влазить у памʼять сторінки.
        $unread = KitchenNotification::latest()->with('client')->whereNull('read_at')->get();
        $read   = KitchenNotification::latest()->with('client')->whereNotNull('read_at')->limit(50)->get();

        return [
            'unread'  => $unread,
            'read'    => $read,
            'authors' => $this->authorsFor($unread->concat($read)),
        ];
    }

    /**
     * Хто оформив замовлення — лише для власника (супер-адміна), щоб бачити,
     * хто з менеджерів продовжив клієнта. Кухні цю позначку не показуємо.
     *
     * Автора беремо з журналу змін замовлення (подія «created»). Замовлення,
     * створене не людиною в адмінці (чат Inbox, кабінет, агент), автора не має.
     *
     * @return array<int, string>|null order_id => імʼя; null — позначку не показувати
     */
    private function authorsFor($notifications): ?array
    {
        if (! auth()->user()?->isSuperAdmin()) {
            return null;
        }

        $orderIds = $notifications->pluck('order_id')->filter()->unique()->values();
        if ($orderIds->isEmpty()) {
            return [];
        }

        try {
            return \Spatie\Activitylog\Models\Activity::query()
                ->where('subject_type', \App\Models\Order::class)
                ->whereIn('subject_id', $orderIds)
                ->where('event', 'created')
                ->whereNotNull('causer_id')
                ->with('causer')
                ->orderBy('id')
                ->get()
                ->mapWithKeys(fn ($a) => [(int) $a->subject_id => (string) ($a->causer?->name ?? '')])
                ->filter()
                ->all();
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    public function markAllRead(): void
    {
        KitchenNotification::markAllAsRead();
        $this->redirect(static::getUrl());
    }

    public function markRead(int $id): void
    {
        KitchenNotification::find($id)?->markAsRead();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('markAllRead')
                ->label('Всі прочитані')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->action('markAllRead')
                ->visible(fn() => KitchenNotification::unreadCount() > 0),
        ];
    }
}
