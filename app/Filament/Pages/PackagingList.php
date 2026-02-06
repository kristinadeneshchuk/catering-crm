<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use App\Models\DailyMenu;
use App\Models\Order;
use App\Models\Setting;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Form;
use Filament\Actions\Action;
use Carbon\Carbon;

class PackagingList extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';
    protected static ?string $navigationLabel = 'Фасувальний лист';
    protected static ?string $title = 'Фасувальний лист';
    protected static string $view = 'filament.pages.packaging-list';

    public ?array $data = [];
    public array $report = [];
    public ?string $debugMessage = null;

    public function mount(): void
    {
        $this->form->fill(['date' => request()->query('date', now()->format('Y-m-d'))]);
        $this->calculate();
    }

    protected function getHeaderActions(): array
    {
        $dateParam = $this->data['date'] ?? now()->format('Y-m-d');
        
        return [
            Action::make('print_stickers')
                ->label('1. Стікери')
                ->icon('heroicon-o-tag')
                ->color('gray')
                ->url(fn () => route('print.stickers', ['date' => $dateParam]))
                ->openUrlInNewTab(),

            Action::make('print_manifest')
                ->label('2. На пакет')
                ->icon('heroicon-o-shopping-bag')
                ->color('warning')
                ->url(fn () => route('print.manifest', ['date' => $dateParam]))
                ->openUrlInNewTab(),

            Action::make('download_logistics')
                ->label('Завантажити логістику (Excel)')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('success')
                ->url(fn () => route('print.logistics', ['date' => $dateParam])),
        ];
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            DatePicker::make('date')
                ->label('Дата фасування')
                ->required()
                ->live()
                ->afterStateUpdated(fn() => $this->calculate()),
        ])->statePath('data');
    }

    public function calculate()
    {
        $date = $this->data['date'] ?? now()->format('Y-m-d');
        $this->report = [];
        $this->debugMessage = null;

        $cycleDays = (int) Setting::where('key', 'menu_cycle_days')->value('value') ?: 24;
        $anchorDate = Carbon::parse('2025-01-01');
        $globalDay = (abs(Carbon::parse($date)->diffInDays($anchorDate)) % $cycleDays) + 1;

        $menu = DailyMenu::where('day_number', $globalDay)
            ->with(['menuItems.dish.dishIngredients.ingredient', 'menuItems.dish.dishIngredients.childDish.dishIngredients.ingredient', 'menuItems.mealType'])
            ->first();

        if (!$menu) {
            $this->debugMessage = "⚠️ Меню на цей день не заповнено";
            return;
        }

        $orders = Order::whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->whereIn('status', ['new', 'active'])
            ->with(['client.mealTypes', 'client.ingredientExclusions', 'replacements.replacementProduct', 'replacements.originalProduct'])
            ->get();

        $sortedMenuItems = $menu->menuItems->sortBy(fn($item) => $item->mealType->sort_order ?? 99);

        foreach ($sortedMenuItems as $mItem) {
            $dish = $mItem->dish;
            if (!$dish) continue;

            $tableData = [
                'meal' => $mItem->mealType->name ?? 'Інше',
                'dish_name' => $dish->name,
                'columns' => [], 
                'rows' => [],
                'individual_notes' => []
            ];

            foreach ($orders as $order) {
                // Якщо клієнт прибрав цей прийом їжі, він просто не потрапляє в цей цикл
                $clientMealTypeIds = $order->client->mealTypes->pluck('id')->toArray();
                if (!in_array($mItem->meal_type_id, $clientMealTypeIds)) continue;

                $activePercentSum = $order->client->mealTypes->sum('energy_percent');
                $factor = ($activePercentSum > 0) ? (100 / $activePercentSum) : 1.0;
                $scale = (float)($order->scale_factor ?: 1.0) * $factor;

                // --- 1. ЗБИРАЄМО НОТАТКИ ПРО ЗАМІНИ (Але ще не вирішуємо про колонку) ---
                $replacements = $order->replacements->where('dish_id', $dish->id)->whereNotNull('original_product_id');
                
                $conflicts = [];
                if ($order->client->ingredientExclusions->isNotEmpty()) {
                    $conflicts = $this->getConflictingIngredients($dish, $order->client->ingredientExclusions);
                }

                $noteParts = [];
                // Заміни
                foreach($replacements as $r) {
                    $noteParts[] = "🔄 " . ($r->originalProduct->name ?? '?') . " ➡ " . ($r->replacementProduct->name ?? '?');
                }
                // Конфлікти (виключення)
                foreach($conflicts as $conflictName) {
                    $noteParts[] = "⛔ Без: {$conflictName}";
                }

                // Якщо є зауваження — додаємо в список внизу
                if (!empty($noteParts)) {
                    $tableData['individual_notes'][] = "• (#{$order->client->id}) {$order->client->name}: " . implode(', ', $noteParts);
                }

                // --- 2. ВИЗНАЧАЄМО КОЛОНКУ ---
                // Окрема колонка ТІЛЬКИ якщо нестандартний розмір порції (Factor > 1.01)
                // (Наприклад, клієнт прибрав обід, і його сніданок став більшим, або у нього кастомні калорії)
                $isCustomColumn = ($factor > 1.01); 

                if ($isCustomColumn) {
                    $colKey = "ID:{$order->client->id} {$order->client->name} (" . (int)$order->calories . ")";
                } else {
                    // Якщо просто заміни — падає в загальну колонку
                    $colKey = (int)$order->calories;
                }

                if (!isset($tableData['columns'][$colKey])) {
                    $tableData['columns'][$colKey] = ['count' => 0, 'scale' => $scale];
                }
                $tableData['columns'][$colKey]['count']++;
            }

            ksort($tableData['columns']);

            foreach ($dish->dishIngredients as $di) {
                if ($di->ingredient) {
                    $originalName = $di->ingredient->name;
                } elseif ($di->childDish) {
                    $originalName = "📦 " . $di->childDish->name;
                } else {
                    $originalName = '???';
                }

                $cells = [];
                foreach ($tableData['columns'] as $key => $col) {
                    $cells[$key] = ['val' => round($di->net_weight_g * $col['scale'])];
                }
                $tableData['rows'][] = ['original_name' => $originalName, 'cells' => $cells];
            }

            if (!empty($tableData['columns'])) $this->report[] = $tableData;
        }
    }

    private function getConflictingIngredients($dish, $exclusions, $prefix = ''): array
    {
        $found = [];
        if (!$dish || !$dish->dishIngredients) return [];

        foreach ($dish->dishIngredients as $di) {
            if ($di->ingredient_id && $exclusions->contains('id', $di->ingredient_id)) {
                $name = $di->ingredient->name . ($prefix ? " (у {$prefix})" : "");
                $found[] = $name;
            }
            if ($di->child_dish_id && $di->childDish) {
                $subFound = $this->getConflictingIngredients($di->childDish, $exclusions, $di->childDish->name);
                $found = array_merge($found, $subFound);
            }
        }
        return $found;
    }
}