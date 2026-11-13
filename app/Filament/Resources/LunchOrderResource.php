<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LunchOrderResource\Pages;
use App\Models\Client;
use App\Models\LunchCompanyLink;
use App\Models\LunchOrder;
use Filament\Forms;
use Filament\Notifications\Notification;
use App\Models\LunchOrderLine;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/**
 * Корпоративні обіди з Lunch Hub: скільки обідів якій компанії на який день.
 *
 * Лише перегляд — дані приходять знімком із Lunch Hub після дедлайну
 * (POST /api/lunch/orders); правити їх тут немає сенсу, наступний знімок перезапише.
 */
class LunchOrderResource extends Resource
{
    protected static ?string $model = LunchOrder::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';
    protected static ?string $navigationLabel = 'Обіди (компанії)';
    protected static ?string $modelLabel = 'Обіди компанії';
    protected static ?string $pluralModelLabel = 'Обіди (компанії)';
    protected static ?int $navigationSort = 6;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return \App\Support\SchemaReady::has('lunch_orders')
            && ($user?->isAdmin() || $user?->isManager());
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['lines', 'client']))
            ->defaultSort('date', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('date')->label('День')->date('d.m.Y')->sortable(),
                Tables\Columns\TextColumn::make('company_name')->label('Компанія')->searchable()
                    ->description(fn (LunchOrder $o) => $o->address),
                Tables\Columns\TextColumn::make('client.name')->label('Клієнт CRM')
                    ->placeholder('не привʼязано')
                    ->color(fn (LunchOrder $o) => $o->client_id ? 'primary' : 'gray')
                    ->url(fn (LunchOrder $o) => $o->client_id ? ClientResource::getUrl('edit', ['record' => $o->client_id]) : null),
                Tables\Columns\TextColumn::make('sets')->label('Обідів')->alignCenter()->weight('bold')
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->label('Разом')),
                Tables\Columns\TextColumn::make('dishes')->label('Страви')->wrap()
                    ->state(fn (LunchOrder $o) => new HtmlString($o->lines
                        ->sortBy(fn (LunchOrderLine $l) => array_search($l->kind, ['main', 'light', 'extra'], true))
                        ->map(fn (LunchOrderLine $l) => e($l->name).' — <b>'.$l->qty.'</b>'
                            .($l->weight_g ? ' × '.$l->weight_g.' г' : '')
                            .($l->kind === 'extra' ? ' <span style="opacity:.6">(доплата)</span>' : '')
                            .(! $l->dish_id || ! $l->weight_g ? ' <span style="color:#dc2626;font-weight:700">— без '.(! $l->dish_id ? 'страви CRM' : 'ваги').', у кухонні документи не потрапляє</span>' : ''))
                        ->implode('<br>')))
                    ->html(),
                Tables\Columns\TextColumn::make('delivery_window')->label('Доставка')->placeholder('—')
                    ->description(fn (LunchOrder $o) => $o->contact),
                Tables\Columns\TextColumn::make('amount')->label('Сума')->money('UAH')->alignEnd()
                    ->description(fn (LunchOrder $o) => $o->company_amount !== null && abs($o->companyDue() - (float) $o->amount) > 0.005
                        ? 'компанія '.number_format($o->companyDue(), 0, '.', ' ').' ₴ · співробітники '.number_format((float) $o->employees_amount, 0, '.', ' ').' ₴'
                        : null)
                    ->visible(fn () => (bool) auth()->user()?->isAdmin())
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->label('Разом')->money('UAH')),
                Tables\Columns\TextColumn::make('received_at')->label('Отримано')->dateTime('d.m H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\Filter::make('upcoming')->label('Сьогодні й далі')->default()
                    ->query(fn (Builder $query) => $query->whereDate('date', '>=', now()->toDateString())),
                Tables\Filters\SelectFilter::make('company_id')->label('Компанія')
                    ->options(fn () => LunchOrder::query()->orderBy('company_name')->pluck('company_name', 'company_id')->all()),
            ])
            ->actions([
                Tables\Actions\Action::make('link')
                    ->label(fn (LunchOrder $o) => $o->client_id ? 'Змінити клієнта' : 'Привʼязати клієнта')
                    ->icon('heroicon-o-link')
                    ->visible(fn () => \App\Support\SchemaReady::has('lunch_company_links'))
                    ->modalHeading(fn (LunchOrder $o) => 'Клієнт CRM для «'.$o->company_name.'»')
                    ->modalDescription('Привʼязка діє на всі дні цієї компанії — минулі й майбутні.')
                    ->fillForm(fn (LunchOrder $o) => ['client_id' => $o->client_id])
                    ->form([
                        Forms\Components\Select::make('client_id')->label('Клієнт')
                            ->searchable()->required()
                            ->getSearchResultsUsing(fn (string $search) => Client::query()
                                ->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"))
                                ->orderBy('name')->limit(20)->get()
                                ->mapWithKeys(fn (Client $c) => [$c->id => $c->name.($c->phone ? ' · '.$c->phone : '')])->all())
                            ->getOptionLabelUsing(fn ($value) => ($c = Client::find($value)) ? $c->name.($c->phone ? ' · '.$c->phone : '') : null)
                            ->helperText('Шукайте за назвою або телефоном. Якщо компанії ще немає серед клієнтів — спершу створіть її в «Клієнтах».'),
                    ])
                    ->action(function (LunchOrder $o, array $data) {
                        LunchCompanyLink::link((int) $o->company_id, $o->company_name, (int) $data['client_id']);
                        Notification::make()->title('Компанію привʼязано до клієнта')->success()->send();
                    }),
                Tables\Actions\Action::make('pay')->label('Оплата обідів')->icon('heroicon-o-banknotes')->color('success')
                    ->visible(fn (LunchOrder $o) => (bool) $o->client_id
                        && in_array(auth()->user()?->role, \App\Models\Transaction::PAYMENT_ROLES, true))
                    ->modalHeading(fn (LunchOrder $o) => 'Оплата обідів — '.($o->client?->name ?? $o->company_name))
                    ->modalDescription('Оплата йде на баланс компанії загалом, а не на окремий день.')
                    ->fillForm(fn (LunchOrder $o) => [
                        'amount' => max(0, round(-1 * ($o->client?->lunchBalance() ?? 0), 2)) ?: null,
                        'date'   => now()->toDateString(),
                    ])
                    ->form([
                        Forms\Components\TextInput::make('amount')->label('Сума, ₴')->numeric()->minValue(0.01)->required()
                            ->helperText('Підставлено поточний борг компанії за обіди.'),
                        Forms\Components\Select::make('account_id')->label('Каса / рахунок')->required()
                            ->options(fn () => \App\Models\Account::orderBy('name')->pluck('name', 'id')->all()),
                        Forms\Components\DatePicker::make('date')->label('Дата оплати')->required()->maxDate(now()),
                        Forms\Components\TextInput::make('comment')->label('Коментар')->maxLength(255)
                            ->placeholder('№ рахунку, період'),
                    ])
                    ->action(function (LunchOrder $o, array $data) {
                        \App\Models\Transaction::create([
                            'type'       => 'income',
                            'category'   => \App\Services\Accounting\CashBook::LUNCH,
                            'client_id'  => $o->client_id,
                            'account_id' => $data['account_id'],
                            'amount'     => $data['amount'],
                            'date'       => $data['date'],
                            'comment'    => trim('Обіди — '.$o->company_name.'. '.($data['comment'] ?? '')),
                            'user_id'    => auth()->id(),
                        ]);
                        Notification::make()->title('Оплату обідів внесено')->success()->send();
                    }),
                Tables\Actions\Action::make('unlink')->label('Відвʼязати')->icon('heroicon-o-x-mark')->color('gray')
                    ->visible(fn (LunchOrder $o) => (bool) $o->client_id && \App\Support\SchemaReady::has('lunch_company_links'))
                    ->requiresConfirmation()
                    ->action(fn (LunchOrder $o) => LunchCompanyLink::unlink((int) $o->company_id)),
            ])
            ->bulkActions([]);
    }

    public static function getWidgets(): array
    {
        return [LunchOrderResource\Widgets\LunchSettlements::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLunchOrders::route('/'),
        ];
    }
}
