<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use App\Models\DailyMenu;
use App\Models\Order;
use App\Models\Ingredient;
use App\Models\Setting; 
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Carbon\Carbon;

class ShoppingList extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';
    protected static ?string $navigationLabel = 'Список покупок';
    protected static ?string $title = 'Список покупок';
    protected static string $view = 'filament.pages.shopping-list';

    public ?array $data = [];
    public array $shoppingList = [];

    public static function canAccess(): bool
    {
        return in_array(auth()->user()->role, ['admin', 'manager']);
    }

    public function mount(): void
    {
        $this->form->fill(['date' => now()->addDay()->format('Y-m-d')]);
        $this->calculate();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                DatePicker::make('date')
                    ->label('На яку дату закуповуємо?')
                    ->displayFormat('d.m.Y')
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn () => $this->calculate()),
            ])
            ->statePath('data');
    }

    public function calculate()
    {
        $date = $this->data['date'] ?? now()->addDay()->format('Y-m-d');
        $needed = []; 

        $cycleDays = (int) Setting::where('key', 'menu_cycle_days')->value('value') ?: 24;
        $anchorDate = Carbon::parse('2025-01-01'); 
        $carbonDate = Carbon::parse($date);
        $diffInDays = abs($carbonDate->diffInDays($anchorDate)); 
        $globalDay = ($diffInDays % $cycleDays) + 1;

        $menu = DailyMenu::where('day_number', $globalDay)
            ->with(['menuItems.dish.dishIngredients.ingredient', 'menuItems.dish.dishIngredients.childDish.dishIngredients.ingredient'])
            ->first();
        
        if ($menu) {
            $orders = Order::whereDate('start_date', '<=', $date)
                ->whereDate('end_date', '>=', $date)
                ->whereIn('status', ['new', 'active'])
                ->with(['client.mealTypes', 'client.ingredientExclusions', 'client.dishExclusions', 'replacements.replacementProduct', 'replacements.replacementDish.dishIngredients.ingredient'])
                ->get();

            foreach ($orders as $order) {
                $activePercentSum = $order->client->mealTypes->sum('energy_percent');
                $redistributionFactor = ($activePercentSum > 0 && $activePercentSum < 100) ? (100 / $activePercentSum) : 1.0;
                $scale = (float)($order->scale_factor ?: 1.0) * $redistributionFactor;

                foreach ($menu->menuItems as $item) {
                    if (!$item->dish) continue;
                    if (!in_array($item->meal_type_id, $order->client->mealTypes->pluck('id')->toArray())) continue;

                    $dishReplacement = $order->replacements->where('dish_id', $item->dish_id)->whereNull('original_product_id')->first();
                    $activeDish = ($dishReplacement && $dishReplacement->replacementDish) ? $dishReplacement->replacementDish : $item->dish;

                    if ($order->client->dishExclusions->contains('id', $item->dish_id) && !$dishReplacement) continue;

                    $ingredients = $this->getScaledIngredientsForShopping($activeDish, $scale, 1, $order);

                    foreach ($ingredients as $ing) {
                        $id = $ing['id'];
                        if (!isset($needed[$id])) {
                            $needed[$id] = ['name' => $ing['name'], 'brutto' => 0, 'unit' => $ing['unit']];
                        }
                        $needed[$id]['brutto'] += $ing['brutto'];
                    }
                }
            }
        }

        $finalList = [];
        foreach ($needed as $id => $info) {
            $dbIng = Ingredient::find($id);
            $stock = (float)($dbIng->stock ?? 0);
            
            $finalList[] = [
                'name' => $info['name'],
                'need' => $info['brutto'], // ВИПРАВЛЕНО: Ключ тепер відповідає шаблону
                'stock' => $stock,
                'to_buy' => max(0, $info['brutto'] - $stock),
                'unit' => $info['unit'],
            ];
        }

        usort($finalList, fn($a, $b) => strcmp($a['name'], $b['name']));
        $this->shoppingList = $finalList;
    }

    private function getScaledIngredientsForShopping($dish, $scale, $subRatio = 1, $order = null): array
    {
        $list = [];
        if (!$dish) return $list;

        foreach ($dish->dishIngredients as $item) {
            $currentK = $scale * $subRatio;
            $type = mb_strtolower(trim($item->type));
            $netto = (float)$item->net_weight_g * $currentK;

            if (in_array($type, ['product', 'продукт']) && $item->ingredient) {
                $ing = $item->ingredient;
                $replacement = $order->replacements->where('dish_id', $dish->id)->where('original_product_id', $ing->id)->first();
                if ($replacement && $replacement->replacementProduct) {
                    $ing = $replacement->replacementProduct;
                }

                $list[] = [
                    'id' => $ing->id,
                    'name' => $ing->name,
                    'brutto' => ($netto * 100) / (float)($ing->yield_percent ?: 100),
                    'unit' => $ing->unit ?? 'г'
                ];
            } elseif (in_array($type, ['pf', 'пф', 'напівфабрикат']) && $item->childDish) {
                $pfBase = (float)$item->childDish->base_weight_g ?: 100;
                $pfRatio = (float)$item->net_weight_g / $pfBase;
                $list = array_merge($list, $this->getScaledIngredientsForShopping($item->childDish, $scale, $pfRatio * $subRatio, $order));
            }
        }
        return $list;
    }
}