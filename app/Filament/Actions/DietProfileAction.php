<?php

namespace App\Filament\Actions;

use App\Models\Client;
use App\Models\ClientDietProfile;
use App\Models\Dish;
use App\Models\MealType;
use App\Services\Agents\IngredientGroupCatalog;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;

/**
 * «Профіль харчування» на картці клієнта: режим, групи «не можна», «краще ні»,
 * правила-частоти, улюблені страви, памʼятка кухні. Його читають агент «Меню» і MenuGuard.
 */
class DietProfileAction
{
    public static function make(): Action
    {
        $groups = collect(IngredientGroupCatalog::all())->except(\App\Models\IngredientGroup::ZERO_KCAL_OK)->map(fn ($g) => $g['name'])->all();

        return Action::make('diet_profile')
            ->label('Профіль харчування')
            ->icon('heroicon-o-clipboard-document-list')
            ->color('gray')
            ->visible(fn () => auth()->user()?->isAdmin() || auth()->user()?->isManager())
            ->modalWidth('3xl')
            ->fillForm(fn (Client $record) => ClientDietProfile::firstWhere('client_id', $record->id)?->toArray() ?? ['mode' => 'cycle_replacements'])
            ->form([
                Forms\Components\Select::make('mode')->label('Режим')->options(ClientDietProfile::MODES)->required(),
                Forms\Components\Select::make('excluded_groups')->label('Не можна — цілими групами')->multiple()->options($groups)
                    ->helperText('Виключили групу — заборонені всі її продукти (кукурудза = зерно, крохмаль, мексиканські овочі). Окремі продукти — у виключеннях клієнта.'),
                Forms\Components\Repeater::make('avoid')->label('Краще ні (мʼяко)')->schema([
                    Forms\Components\Select::make('group_slug')->label('Група')->options($groups),
                    Forms\Components\Select::make('dish_id')->label('Або страва (не сподобалась)')->searchable()
                        ->getSearchResultsUsing(fn (string $search) => Dish::where('name', 'like', "%{$search}%")->limit(30)->pluck('name', 'id'))
                        ->getOptionLabelUsing(fn ($value) => Dish::find($value)?->name),
                    Forms\Components\TextInput::make('text')->label('Як сказав клієнт'),
                ])->columns(3)->defaultItems(0)->addActionLabel('Додати')
                    ->helperText('Група або страва — не блокує, але агент не ставить, а перевірка попереджає. Жорстке «не їм» — у виключеннях клієнта.'),
                Forms\Components\TagsInput::make('want_more')->label('Більше / улюблене'),
                Forms\Components\Repeater::make('rules')->label('Правила')->schema([
                    Forms\Components\Select::make('kind')->label('Тип')->options(ClientDietProfile::RULE_KINDS)->default('text')->required()->live(),
                    Forms\Components\Select::make('group_slug')->label('Група')->options($groups)->searchable()
                        ->visible(fn (Forms\Get $get) => in_array($get('kind'), ['max_per_day', 'max_per_week', 'min_gap_days', 'meal_no_group', 'meal_only_group'], true)),
                    Forms\Components\Select::make('dish_id')->label('Або страва')->searchable()
                        ->getSearchResultsUsing(fn (string $search) => Dish::where('name', 'like', "%{$search}%")->limit(30)->pluck('name', 'id'))
                        ->getOptionLabelUsing(fn ($value) => Dish::find($value)?->name)
                        ->visible(fn (Forms\Get $get) => $get('kind') === 'min_gap_days'),
                    Forms\Components\Select::make('meal_type_id')->label('Прийом')->options(fn () => MealType::query()->orderBy('sort_order')->pluck('name', 'id'))
                        ->visible(fn (Forms\Get $get) => in_array($get('kind'), ['meal_no_group', 'meal_only_group'], true)),
                    Forms\Components\TextInput::make('value')->label('N')->numeric()->minValue(0)
                        ->visible(fn (Forms\Get $get) => in_array($get('kind'), ['max_per_day', 'max_per_week', 'min_gap_days'], true)),
                    Forms\Components\Select::make('serve')->label('Подача')->options(ClientDietProfile::SERVE_CODES)
                        ->visible(fn (Forms\Get $get) => $get('kind') === 'serve'),
                    Forms\Components\TextInput::make('text')->label('Як сказав клієнт')->columnSpanFull(),
                ])->columns(3)->defaultItems(0)->addActionLabel('Додати правило')
                    ->helperText('«Текстом» код не перевіряє — по змозі обирайте тип: «каша раз на день» = Не більше 1 на день + група «Крупи»; «вечеря без вуглеводів» = Прийом без групи + Вечеря + «Вуглеводний гарнір».'),
                Forms\Components\Select::make('favorite_dish_ids')->label('Улюблені страви')->multiple()->searchable()
                    ->getSearchResultsUsing(fn (string $search) => Dish::where('name', 'like', "%{$search}%")->limit(30)->pluck('name', 'id'))
                    ->getOptionLabelsUsing(fn (array $values) => Dish::whereIn('id', $values)->pluck('name', 'id')),
                Forms\Components\Textarea::make('kitchen_memo')->label('Памʼятка кухні')->rows(3)->helperText('Бачить кухня: без грошей і без згадок ШІ.'),
                Forms\Components\Textarea::make('notes')->label('Нотатки для менеджера й агента')->rows(4),
            ])
            ->action(function (array $data, Client $record) {
                ClientDietProfile::updateOrCreate(['client_id' => $record->id], $data + ['updated_by' => auth()->id()]);
                Notification::make()->title('Профіль харчування збережено')->success()->send();
            });
    }
}
