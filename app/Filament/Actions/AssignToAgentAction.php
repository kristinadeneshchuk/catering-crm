<?php

namespace App\Filament\Actions;

use App\Filament\Resources\AiAgentTaskResource;
use App\Models\AiAgent;
use App\Models\AiAgentTask;
use App\Models\Client;
use App\Models\Order;
use App\Services\Agents\TaskDesk;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Get;
use Filament\Notifications\Notification;

/**
 * Кнопка «Доручити агенту» на картці клієнта й замовлення (docs/tz-ai-agents-in-crm.md §3.3).
 */
class AssignToAgentAction
{
    public static function make(): Action
    {
        return Action::make('assign_to_agent')
            ->label('Доручити агенту')
            ->icon('heroicon-o-sparkles')
            ->color('info')
            ->visible(fn () => auth()->user()?->isAdmin() || auth()->user()?->isManager())
            ->modalHeading('Доручити агенту')
            ->modalSubmitActionLabel('Доручити')
            ->form(fn ($record) => [
                Forms\Components\Select::make('template')->label('Що зробити')->required()->live()
                    ->options(AiAgentTask::TEMPLATES)->default('menu_period'),
                Forms\Components\Select::make('order_id')->label('Замовлення')->required()
                    ->options(fn () => self::orders($record))
                    ->default(fn () => $record instanceof Order ? $record->id : array_key_first(self::orders($record)))
                    ->visible(fn () => $record instanceof Client),
                Forms\Components\Grid::make(2)->schema([
                    Forms\Components\DatePicker::make('period_from')->label('З')->native(false)->displayFormat('d.m.Y')
                        ->default(now()->addDay())->required(fn (Get $get) => in_array($get('template'), ['menu_period', 'replacements'], true)),
                    Forms\Components\DatePicker::make('period_to')->label('По')->native(false)->displayFormat('d.m.Y')
                        ->default(now()->addDays(7))->afterOrEqual('period_from'),
                ]),
                Forms\Components\Textarea::make('details')->label('Побажання, контекст')->rows(4)
                    ->placeholder('Напр.: клієнтка просить більше риби, без повторів з минулого тижня')
                    ->required(fn (Get $get) => $get('template') === 'free'),
                Forms\Components\Select::make('ai_agent_id')->label('Агент')
                    ->options(fn () => AiAgent::where('is_active', true)->pluck('name', 'id'))
                    ->default(fn () => AiAgent::serverMenu()->id)
                    ->helperText('«Меню (сервер)» працює одразу в CRM. Інші агенти заберуть задачу самі.'),
            ])
            ->action(function (array $data, $record) {
                $order = $record instanceof Order ? $record : Order::find($data['order_id'] ?? null);
                $agent = AiAgent::find($data['ai_agent_id'] ?? null) ?? AiAgent::serverMenu();
                $task = app(TaskDesk::class)->assign($agent, auth()->user(), $data + [
                    'order_id' => $order?->id,
                    'client_id' => $record instanceof Client ? $record->id : $order?->client_id,
                ]);

                Notification::make()->title("Задачу #{$task->id} доручено: {$agent->name}")->success()
                    ->actions([\Filament\Notifications\Actions\Action::make('open')->label('Відкрити')->url(AiAgentTaskResource::getUrl('view', ['record' => $task]))])
                    ->send();
            });
    }

    /** Замовлення клієнта, свіжі першими (активні — нагорі). */
    private static function orders($record): array
    {
        $clientId = $record instanceof Client ? $record->id : $record?->client_id;

        return Order::where('client_id', $clientId)->orderByRaw("CASE WHEN status IN ('active','new','paused') THEN 0 ELSE 1 END")->latest('start_date')->limit(20)->get()
            ->mapWithKeys(fn (Order $o) => [$o->id => "#{$o->id} · " . ($o->start_date ? \Carbon\Carbon::parse($o->start_date)->format('d.m') : '?') . '–' . ($o->end_date ? \Carbon\Carbon::parse($o->end_date)->format('d.m') : '?') . " · {$o->calories} ккал · " . ($o->status ?? '')])
            ->all();
    }
}
