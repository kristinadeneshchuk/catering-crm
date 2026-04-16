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
        $notifications = KitchenNotification::latest()
            ->with('client')
            ->get()
            ->groupBy(fn($n) => $n->read_at ? 'read' : 'unread');

        return [
            'unread' => $notifications->get('unread', collect()),
            'read'   => $notifications->get('read', collect()),
        ];
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
