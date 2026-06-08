<?php

namespace App\Filament\Pages;

use App\Models\Order;
use App\Models\OrderCall;
use App\Traits\RestrictCookAccess;
use Mokhosh\FilamentKanban\Pages\KanbanBoard;
use Illuminate\Support\Collection;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Carbon\Carbon;

class RetentionBoard extends KanbanBoard
{
    use RestrictCookAccess;

    protected static string $model = OrderCall::class;
    
    protected static ?string $navigationIcon = 'heroicon-o-phone-arrow-up-right';
    protected static ?string $navigationLabel = '📞 Продовження (Гарячі)';
    protected static ?string $title = 'Воронка утримання (Активні задачі)';
    protected static ?int $navigationSort = 4;

    protected static string $recordView = 'filament.pages.retention-card';
    protected static string $headerView = 'filament.pages.retention-header';
    protected static string $statusView = 'filament.pages.retention-status';

    public static function getNavigationBadge(): ?string
    {
        // Рахуємо ТІЛЬКИ свіжих клієнтів (від -4 днів і далі), у яких статус 'new'
        $count = OrderCall::where('status', 'new')
            ->whereHas('order', function ($query) {
                $query->where('end_date', '>=', now()->subDays(4)->format('Y-m-d'));
            })
            ->count();
            
        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    protected function statuses(): Collection
    {
        return collect([
            ['id' => 'new', 'title' => '🔵 Треба подзвонити'],
            ['id' => 'no_answer', 'title' => '🟡 Не бере слухавку'],
            ['id' => 'thinking', 'title' => '🟠 Думає / Перенести'],
            ['id' => 'refused', 'title' => '🔴 Відмова'],
            ['id' => 'success', 'title' => '🟢 Продовжено'],
        ]);
    }

    // === ЯКІ КАРТКИ ВИВОДИТИ (АВТООЧИЩЕННЯ ВІД ХОЛОДНИХ) ===
    protected function records(): Collection
    {
        return OrderCall::select('order_calls.*')
            ->join('orders', 'orders.id', '=', 'order_calls.order_id')
            ->with(['client', 'order'])
            
            // 🔥 Головне: Беремо ТІЛЬКИ свіжих (не більше 4 днів як відвалилися)
            ->where('orders.end_date', '>=', now()->subDays(4)->format('Y-m-d'))
            
            // АВТО-ОЧИЩЕННЯ: Ховаємо картки "Відмова" та "Успіх", якщо після зміни статусу пройшло більше 24 годин
            ->where(function ($query) {
                $query->whereNotIn('order_calls.status', ['success', 'refused'])
                      ->orWhere('order_calls.updated_at', '>=', now()->subDay());
            })
            
            // СОРТУВАННЯ: Спочатку майбутні/сьогодні (0, 1, 2, 3 дн.), потім вже прострочені (-1, -2...)
            ->orderByRaw('CASE WHEN orders.end_date >= CURDATE() THEN 0 ELSE 1 END ASC')
            ->orderByRaw('CASE WHEN orders.end_date >= CURDATE() THEN DATEDIFF(orders.end_date, CURDATE()) ELSE DATEDIFF(CURDATE(), orders.end_date) END ASC')
            ->get();
    }

    public function onStatusChanged(int|string $recordId, string $status, array $fromOrderedIds, array $toOrderedIds): void
    {
        OrderCall::where('id', $recordId)->update(['status' => $status]);
    }

    protected function getEditModalFormSchema(null|int|string $recordId): array
    {
        return [
            Select::make('status')
                ->label('Статус дзвінка')
                ->options([
                    'new' => '🔵 Треба подзвонити',
                    'no_answer' => '🟡 Не бере слухавку',
                    'thinking' => '🟠 Думає / Перенести',
                    'refused' => '🔴 Відмова',
                    'success' => '🟢 Продовжено',
                ])
                ->required(),
                
            Textarea::make('comment')
                ->label('Коментар менеджера після розмови')
                ->rows(3),
                
            DateTimePicker::make('next_call_at')
                ->label('Коли перетелефонувати? (Якщо думає)')
                ->native(false),
                
            Select::make('refusal_reason')
                ->label('Причина відмови')
                ->options(OrderCall::refusalReasons())
                ->visible(fn (\Filament\Forms\Get $get) => $get('status') === 'refused'),
        ];
    }

    protected function editRecord($recordId, array $data, array $state): void
    {
        OrderCall::where('id', $recordId)->update($data);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sync_calls')
                ->label('Згенерувати задачі на прозвон')
                ->color('primary')
                ->action(function () {
                    $this->generateCalls();
                }),

            Action::make('repeat_clients_report')
                ->label('Клієнти 1–3 замовлення')
                ->icon('heroicon-o-phone-arrow-up-right')
                ->color('gray')
                ->url(fn () => route('print.repeat-clients'))
                ->openUrlInNewTab(),
        ];
    }

    private function generateCalls()
    {
        $latestOrders = Order::whereIn('id', function($query) {
            $query->selectRaw('MAX(id)')->from('orders')->groupBy('client_id');
        })
        // 🔥 ОБМЕЖЕННЯ ДЛЯ ГЕНЕРАЦІЇ: Шукаємо тільки в діапазоні від -4 до +3 днів.
        ->whereBetween('end_date', [
            now()->subDays(4)->format('Y-m-d'), 
            now()->addDays(3)->format('Y-m-d')
        ])
        ->get();

        $added = 0;
        foreach ($latestOrders as $order) {
            $exists = OrderCall::where('order_id', $order->id)->exists();
            if (!$exists) {
                OrderCall::create([
                    'order_id' => $order->id,
                    'client_id' => $order->client_id,
                    'status' => 'new',
                ]);
                $added++;
            }
        }

        \Filament\Notifications\Notification::make()
            ->title("Згенеровано нових задач: {$added}")
            ->success()
            ->send();
    }
}