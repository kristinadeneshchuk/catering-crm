<?php

namespace App\Filament\Pages;

use App\Models\Employee;
use App\Models\KitchenDailyPlan;
use App\Services\KitchenPlanService;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class KitchenPlan extends Page
{
    protected static ?string $navigationIcon        = 'heroicon-o-clipboard-document-list';
    protected static ?string $navigationLabel       = 'План кухні';
    protected static ?string $title                 = 'План кухні';
    protected static string  $view                  = 'filament.pages.kitchen-plan';
    protected static bool    $shouldRegisterNavigation = false;

    /** Дата для якої відображаємо план (завтра) */
    public string $planDate;

    /** Збережений план з БД (масив або null) */
    public ?array $plan = null;

    /** Стан генерації */
    public bool $isGenerating = false;

    /** Вибрані співробітники (масив id) */
    public array $selectedEmployees = [];

    public static function canAccess(): bool
    {
        return in_array(auth()->user()->role, ['admin', 'manager', 'cook'], true);
    }

    public function mount(): void
    {
        $this->planDate = Carbon::tomorrow()->format('Y-m-d');
        $this->loadPlan();

        // За замовчуванням відмічаємо всіх активних кухарів та пакувальників
        $this->selectedEmployees = Employee::where('is_active', true)
            ->whereIn('position', ['cook', 'packer'])
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->toArray();
    }

    private function loadPlan(): void
    {
        $record    = KitchenDailyPlan::where('date', $this->planDate)->first();
        $this->plan = $record?->plan_json;
    }

    // -------------------------------------------------------------------------
    // Отримати список активних співробітників для відображення чекбоксів
    // -------------------------------------------------------------------------

    public function getActiveEmployeesProperty(): \Illuminate\Support\Collection
    {
        return Employee::where('is_active', true)
            ->orderByRaw("FIELD(position, 'cook', 'packer', 'manager', 'courier', 'admin')")
            ->get()
            ->map(function ($e) {
                $posLabel = match ($e->position) {
                    'cook'    => 'Кухар',
                    'packer'  => 'Пакувальник',
                    'courier' => 'Кур\'єр',
                    'manager' => 'Менеджер',
                    'admin'   => 'Адміністратор',
                    default   => $e->position,
                };
                return [
                    'id'       => $e->id,
                    'name'     => $e->name,
                    'position' => $e->position,
                    'label'    => "{$e->name} — {$posLabel}",
                ];
            });
    }

    // -------------------------------------------------------------------------
    // Генерація плану
    // -------------------------------------------------------------------------

    public function generate(): void
    {
        // Подвійна перевірка — план вже існує
        if (KitchenDailyPlan::where('date', $this->planDate)->exists()) {
            Notification::make()
                ->title('План вже існує')
                ->body('План на ' . Carbon::parse($this->planDate)->format('d.m.Y') . ' вже згенеровано.')
                ->warning()
                ->send();
            $this->loadPlan();
            return;
        }

        if (empty($this->selectedEmployees)) {
            Notification::make()
                ->title('Оберіть бригаду')
                ->body('Відмітьте хоча б одного співробітника перед генерацією.')
                ->warning()
                ->send();
            return;
        }

        $this->isGenerating = true;

        try {
            // Збираємо вибраних співробітників
            $employees = Employee::whereIn('id', $this->selectedEmployees)
                ->get()
                ->map(fn ($e) => ['id' => $e->id, 'name' => $e->name, 'position' => $e->position])
                ->toArray();

            $service = app(KitchenPlanService::class);
            $planData = $service->generate($this->planDate, $employees);

            KitchenDailyPlan::create([
                'date'         => $this->planDate,
                'plan_json'    => $planData,
                'generated_by' => auth()->user()->name ?? 'Система',
            ]);

            $this->loadPlan();

            Notification::make()
                ->title('План готовий!')
                ->body('План кухні на ' . Carbon::parse($this->planDate)->format('d.m.Y') . ' успішно згенеровано.')
                ->success()
                ->send();

        } catch (\Throwable $e) {
            Notification::make()
                ->title('Помилка генерації')
                ->body($e->getMessage())
                ->danger()
                ->send();
        } finally {
            $this->isGenerating = false;
        }
    }

    // -------------------------------------------------------------------------
    // Дозволити перегенерацію (тільки для admin/manager)
    // -------------------------------------------------------------------------

    protected function getHeaderActions(): array
    {
        if (!$this->plan) return [];

        $canRegenerate = in_array(auth()->user()->role, ['admin', 'manager'], true);

        if (!$canRegenerate) return [];

        return [
            Action::make('regenerate')
                ->label('Перегенерувати план')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Перегенерувати план?')
                ->modalDescription('Поточний план буде видалено і створено новий. Цю дію неможливо скасувати.')
                ->action(function () {
                    KitchenDailyPlan::where('date', $this->planDate)->delete();
                    $this->plan = null;

                    Notification::make()
                        ->title('План видалено')
                        ->body('Тепер ви можете обрати бригаду та згенерувати новий план.')
                        ->info()
                        ->send();
                }),
        ];
    }
}
