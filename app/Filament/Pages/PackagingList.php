<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use App\Models\DailyMenu;
use App\Models\Order;
use App\Models\Setting;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Placeholder;
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
    // Беремо дату, яка зараз обрана в календарі сторінки (наприклад, 16.02)
    $dateParam = $this->data['date'] ?? now()->format('Y-m-d');
    
    return [
        Action::make('print_stickers')
            ->label('1. Стікери')
            ->icon('heroicon-o-tag')
            ->color('gray')
            ->url(fn () => route('print.stickers', ['date' => $dateParam])) // Передаємо 16.02
            ->openUrlInNewTab(),

        Action::make('print_manifest')
            ->label('2. На пакет')
            ->icon('heroicon-o-shopping-bag')
            ->color('warning')
            ->url(fn () => route('print.manifest', ['date' => $dateParam])) // Передаємо 16.02
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
            Grid::make(2)->schema([
                DatePicker::make('date')
                    ->label('Дата фасування (сьогодні)')
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn() => $this->calculate()),

                Placeholder::make('info_text')
                    ->label('Цільова дата')
                    ->content(function() {
                        $selectedDate = $this->data['date'] ?? now()->format('Y-m-d');
                        $targetDateObj = \Carbon\Carbon::parse($selectedDate)->addDay();
                        
                        $cycleDays = (int) Setting::where('key', 'menu_cycle_days')->value('value') ?: 24;
                        $startDateStr = Setting::where('key', 'menu_cycle_start_date')->value('value') ?: '2025-01-01';
                        $anchorDate = Carbon::parse($startDateStr);
                        
                        $diff = abs($targetDateObj->diffInDays($anchorDate));
                        $dayNum = ($diff % $cycleDays) + 1;

                        return new \Illuminate\Support\HtmlString(
                            "<div class='p-2 bg-primary-500/10 border border-primary-500 rounded-lg text-primary-600'>
                                📦 Фасування на <strong>завтра (" . $targetDateObj->format('d.m.Y') . ")</strong>. 
                                <br> Це буде <strong>" . $dayNum . "-й день</strong> циклу меню.
                            </div>"
                        );
                    }),
            ])
        ])->statePath('data');
    }

    public function calculate()
    {
        $selectedDate = $this->data['date'] ?? now()->format('Y-m-d');
        $targetDate = \Carbon\Carbon::parse($selectedDate)->addDay();
        
        $this->report = [];
        
        $cycleDays = (int) Setting::where('key', 'menu_cycle_days')->value('value') ?: 24;
        $startDateStr = Setting::where('key', 'menu_cycle_start_date')->value('value') ?: '2025-01-01';
        $anchorDate = Carbon::parse($startDateStr);
        
        $diff = abs($targetDate->diffInDays($anchorDate));
        $globalDay = ($diff % $cycleDays) + 1;

        $menu = DailyMenu::where('day_number', $globalDay)
            ->with(['menuItems.dish.dishIngredients.ingredient', 'menuItems.dish.dishIngredients.childDish.dishIngredients.ingredient', 'menuItems.mealType'])
            ->first();

        if (!$menu) {
            return;
        }

        // Очищуємо debugMessage якщо меню знайдено
        $this->debugMessage = null;

        $orders = Order::whereIn('status', ['new', 'active'])
            ->whereHas('orderDays', function ($query) use ($targetDate) {
                $query->where('date', $targetDate->format('Y-m-d'));
            })
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
                $clientMealTypeIds = $order->client->mealTypes->pluck('id')->toArray();
                if (!in_array($mItem->meal_type_id, $clientMealTypeIds)) continue;

                $activePercentSum = $order->client->mealTypes->sum('energy_percent');
                $factor = ($activePercentSum > 0) ? (100 / $activePercentSum) : 1.0;
                $scale = (float)($order->scale_factor ?: 1.0) * $factor;

                $replacements = $order->replacements->where('dish_id', $dish->id)->whereNotNull('original_product_id');
                
                $conflicts = [];
                if ($order->client->ingredientExclusions->isNotEmpty()) {
                    $conflicts = $this->getConflictingIngredients($dish, $order->client->ingredientExclusions);
                }

                $noteParts = [];
                foreach($replacements as $r) {
                    $noteParts[] = "🔄 " . ($r->originalProduct->name ?? '?') . " ➡ " . ($r->replacementProduct->name ?? '?');
                }
                foreach($conflicts as $conflictName) {
                    $noteParts[] = "⛔ Без: {$conflictName}";
                }

                if (!empty($noteParts)) {
                    $tableData['individual_notes'][] = "• (#{$order->client->id}) {$order->client->name}: " . implode(', ', $noteParts);
                }

                $isCustomColumn = ($factor > 1.01);
                $colKey = $isCustomColumn ? "ID:{$order->client->id} {$order->client->name} (" . (int)$order->calories . ")" : (int)$order->calories;

                if (!isset($tableData['columns'][$colKey])) {
                    $tableData['columns'][$colKey] = ['count' => 0, 'scale' => $scale];
                }
                $tableData['columns'][$colKey]['count']++;
            }

            ksort($tableData['columns']);

            foreach ($dish->dishIngredients as $di) {
                $originalName = $di->ingredient ? $di->ingredient->name : ($di->childDish ? "📦 " . $di->childDish->name : '???');
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