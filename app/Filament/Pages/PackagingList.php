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

            // === ОНОВЛЕНА КНОПКА ЗАВАНТАЖЕННЯ ===
            Action::make('download_logistics') // Унікальна назва дії
                ->label('Завантажити логістику (Excel)') // Текст кнопки
                ->icon('heroicon-o-arrow-down-tray') // Іконка "скачати"
                ->color('success') // Зелений колір
                // Ми залишаємо direct download, бо це стандарт для файлів. 
                // Браузер сам зрозуміє, що це файл, і не буде переходити на білу сторінку.
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
            ->with(['menuItems.dish.dishIngredients.ingredient', 'menuItems.dish.dishIngredients.childDish', 'menuItems.mealType'])
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
                $clientMealTypeIds = $order->client->mealTypes->pluck('id')->toArray();
                if (!in_array($mItem->meal_type_id, $clientMealTypeIds)) continue;

                $activePercentSum = $order->client->mealTypes->sum('energy_percent');
                $factor = ($activePercentSum > 0) ? (100 / $activePercentSum) : 1.0;
                $scale = (float)($order->scale_factor ?: 1.0) * $factor;

                $replacements = $order->replacements->where('dish_id', $dish->id)->whereNotNull('original_product_id');

                $isIndivScale = $factor > 1.01;
                
                if ($isIndivScale) {
                    $colKey = "ID:{$order->client->id} {$order->client->name} ({$order->calories})";
                } else {
                    $colKey = (int)$order->calories;
                }

                foreach($replacements as $r) {
                    // === ВИПРАВЛЕНИЙ ПОШУК ВАГИ (ВРАХОВУЄ ПФ) ===
                    $gramAmount = 0;
                    foreach ($dish->dishIngredients as $di) {
                        // Якщо інгредієнт на верхньому рівні
                        if ($di->ingredient_id == $r->original_product_id) {
                            $gramAmount = round($di->net_weight_g * $scale);
                            break;
                        }
                        // Якщо інгредієнт всередині напівфабрикату
                        if ($di->child_dish_id && $di->childDish) {
                            $subIng = $di->childDish->dishIngredients->where('ingredient_id', $r->original_product_id)->first();
                            if ($subIng) {
                                $pfBaseWeight = (float)($di->childDish->base_weight_g ?: 100);
                                // Пропорція: (вага інгредієнта в ПФ * вага ПФ у страві) / база ПФ * загальний масштаб
                                $gramAmount = round(($subIng->net_weight_g * $di->net_weight_g / $pfBaseWeight) * $scale);
                                break;
                            }
                        }
                    }
                    
                    $tableData['individual_notes'][] = "• (#{$order->client->id}) {$order->client->name}: " . 
                        ($r->originalProduct->name ?? '???') . " ➡ " . 
                        ($r->replacementProduct->name ?? '???') . " ({$gramAmount} г)";
                }

                if (!isset($tableData['columns'][$colKey])) {
                    $tableData['columns'][$colKey] = ['count' => 0, 'scale' => $scale];
                }
                $tableData['columns'][$colKey]['count']++;
            }

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
}