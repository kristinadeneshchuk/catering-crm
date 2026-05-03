<?php

namespace App\Filament\Resources\DailyMenuResource\Pages;

use App\Filament\Resources\DailyMenuResource;
use App\Models\MenuPlan;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListDailyMenus extends ListRecords
{
    protected static string $resource = DailyMenuResource::class;

    /**
     * Активний план = поточна вкладка. Зберігається в URL через `?activeTab=<id>`.
     */
    public function getTabs(): array
    {
        $tabs = [];
        $plans = MenuPlan::withCount('dailyMenus')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        foreach ($plans as $plan) {
            $planId = $plan->id;
            $tabs[(string) $plan->id] = Tab::make($plan->name)
                ->badge($plan->daily_menus_count)
                ->modifyQueryUsing(function ($query) use ($planId) {
                    return $query->where('menu_plan_id', $planId);
                });
        }

        return $tabs;
    }

    protected function getHeaderActions(): array
    {
        return [
            // Створення нового плану меню (вкладки)
            Actions\Action::make('createPlan')
                ->label('Новий план меню')
                ->icon('heroicon-o-plus-circle')
                ->color('info')
                ->form([
                    Forms\Components\TextInput::make('name')
                        ->label('Назва')
                        ->placeholder('напр. Преміум, Веган')
                        ->required(),
                    Forms\Components\Textarea::make('description')->label('Опис')->rows(2),
                    Forms\Components\TextInput::make('cycle_days')
                        ->label('Довжина циклу (днів)')
                        ->numeric()->minValue(1)->maxValue(60)->default(24)->required(),
                    Forms\Components\DatePicker::make('cycle_start_date')
                        ->label('Якірна дата циклу')
                        ->default(now()->format('Y-m-d'))->required(),
                    Forms\Components\Toggle::make('is_default')
                        ->label('План за замовчуванням для нових замовлень')
                        ->default(false),
                ])
                ->action(function (array $data) {
                    if (!empty($data['is_default'])) {
                        MenuPlan::query()->update(['is_default' => false]);
                    }
                    $plan = MenuPlan::create([
                        'name'             => $data['name'],
                        'description'      => $data['description'] ?? null,
                        'cycle_days'       => (int) $data['cycle_days'],
                        'cycle_start_date' => $data['cycle_start_date'],
                        'is_default'       => (bool) ($data['is_default'] ?? false),
                        'sort_order'       => (int) (MenuPlan::max('sort_order') ?? 0) + 1,
                    ]);

                    Notification::make()
                        ->title("План «{$plan->name}» створено")
                        ->success()->send();

                    $this->redirect(static::getResource()::getUrl('index', ['activeTab' => (string) $plan->id]));
                }),

            // Редагування / видалення активного плану
            Actions\Action::make('editPlan')
                ->label('Налаштування плану')
                ->icon('heroicon-o-cog-6-tooth')
                ->color('gray')
                ->visible(fn () => (bool) $this->activeTab)
                ->fillForm(function () {
                    $plan = MenuPlan::find($this->activeTab);
                    return $plan?->only(['name', 'description', 'cycle_days', 'cycle_start_date', 'is_default']) ?? [];
                })
                ->form([
                    Forms\Components\TextInput::make('name')->label('Назва')->required(),
                    Forms\Components\Textarea::make('description')->label('Опис')->rows(2),
                    Forms\Components\TextInput::make('cycle_days')
                        ->label('Довжина циклу (днів)')
                        ->numeric()->minValue(1)->maxValue(60)->required(),
                    Forms\Components\DatePicker::make('cycle_start_date')->label('Якірна дата циклу')->required(),
                    Forms\Components\Toggle::make('is_default')->label('План за замовчуванням'),
                ])
                ->action(function (array $data) {
                    $plan = MenuPlan::find($this->activeTab);
                    if (!$plan) return;

                    if (!empty($data['is_default']) && !$plan->is_default) {
                        MenuPlan::query()->update(['is_default' => false]);
                    }

                    $plan->update([
                        'name'             => $data['name'],
                        'description'      => $data['description'] ?? null,
                        'cycle_days'       => (int) $data['cycle_days'],
                        'cycle_start_date' => $data['cycle_start_date'],
                        'is_default'       => (bool) ($data['is_default'] ?? false),
                    ]);

                    Notification::make()->title('План оновлено')->success()->send();
                }),

            Actions\Action::make('deletePlan')
                ->label('Видалити план')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->visible(function () {
                    if (!$this->activeTab) return false;
                    $plan = MenuPlan::find($this->activeTab);
                    return $plan && !$plan->is_default;
                })
                ->requiresConfirmation()
                ->modalDescription('Усі дні меню цього плану будуть видалені, замовлення з цим планом залишаться без прив\'язки.')
                ->action(function () {
                    $plan = MenuPlan::find($this->activeTab);
                    if (!$plan || $plan->is_default) return;

                    $name = $plan->name;
                    $plan->delete();

                    Notification::make()->title("План «{$name}» видалено")->success()->send();
                    $this->redirect(static::getResource()::getUrl('index'));
                }),

            Actions\CreateAction::make()
                ->label('Додати день')
                ->url(fn () => static::getResource()::getUrl('create', [
                    'menu_plan_id' => $this->activeTab,
                ])),
        ];
    }
}
