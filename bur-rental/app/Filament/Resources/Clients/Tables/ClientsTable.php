<?php

namespace App\Filament\Resources\Clients\Tables;

use App\Models\Client;
use App\Services\Loyalty;
use App\Services\WinBack;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ClientsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->withCount('bookings')
                // Виручка — тільки по закритих бронях: підтверджена й видана
                // ще можуть скасуватися, це не гроші. Застава не рахується —
                // вона повертається клієнту.
                ->withSum(
                    ['bookings as revenue' => fn (Builder $q) => $q->where('status', 'closed')],
                    DB::raw('rent_total + extras_total + delivery_total')
                )
                ->withCount(['bookings as active_count' => fn (Builder $q) => $q->whereIn('status', ['new', 'confirmed', 'issued'])])
                ->withMax('bookings as last_rent', 'date_from'))
            ->columns([
                TextColumn::make('phone')
                    ->label('Телефон')
                    ->state(fn (Client $r) => $r->display_phone)
                    ->description(fn (Client $r) => $r->company ?: $r->name ?: null)
                    ->searchable(['phone', 'name', 'company', 'email'])
                    ->copyable()
                    ->weight('bold'),

                TextColumn::make('bookings_count')
                    ->label('Оренд')
                    ->badge()
                    // Двічі — це вже не випадковий клієнт, а той, кого варто
                    // впізнавати по телефону.
                    ->color(fn (int $state) => $state >= 2 ? 'success' : 'gray')
                    ->sortable(),

                TextColumn::make('active_count')
                    ->label('Зараз на руках')
                    ->badge()
                    ->color(fn (int $state) => $state > 0 ? 'primary' : 'gray')
                    ->sortable(),

                TextColumn::make('revenue')
                    ->label('Приніс')
                    ->money('UAH', 1)
                    ->sortable()
                    ->description('без застави'),

                TextColumn::make('discount_percent')
                    ->label('Знижка')
                    ->badge()
                    ->color(fn (Client $r) => $r->discount_percent !== null ? 'warning' : 'gray')
                    // Ручна знижка позначена окремо: менеджер має бачити, що
                    // цифра стоїть руками, а не порахувалася за історією.
                    ->state(fn (Client $r) => app(Loyalty::class)->percentFor($r).'%'
                        .($r->discount_percent !== null ? ' ручна' : ''))
                    ->placeholder('—'),

                TextColumn::make('last_rent')
                    ->label('Остання оренда')
                    ->date('d.m.Y')
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('last_login_at')
                    ->label('Був у кабінеті')
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('не заходив')
                    ->toggleable()
                    ->sortable(),

                TextColumn::make('email')->label('Пошта')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('edrpou')->label('ЄДРПОУ')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('last_rent', 'desc')
            ->filters([
                Filter::make('active')
                    ->label('Зараз орендують')
                    ->query(fn (Builder $q) => $q->whereHas('bookings', fn (Builder $b) => $b->whereIn('status', ['new', 'confirmed', 'issued']))),

                Filter::make('repeat')
                    ->label('Постійні (2+ оренди)')
                    ->query(fn (Builder $q) => $q->has('bookings', '>=', 2)),

                Filter::make('winback')
                    ->label('Давно не орендували')
                    ->query(fn (Builder $q) => $q->whereKey(app(WinBack::class)->due()->modelKeys())),

                Filter::make('company')
                    ->label('Юрособи')
                    ->query(fn (Builder $q) => $q->whereNotNull('company')),
            ])
            ->recordActions([
                // Менеджер виправив одруківку в телефоні броні — броні треба
                // перепідв'язати, інакше клієнт їх у кабінеті не побачить.
                Action::make('claim')
                    ->label('Підтягнути броні')
                    ->icon('heroicon-o-link')
                    ->requiresConfirmation()
                    ->modalDescription('Знайде броні з цим номером, які ще ні до кого не прив\'язані, і додасть їх у кабінет клієнта.')
                    ->action(function (Client $record) {
                        $count = $record->claimBookings();

                        Notification::make()
                            ->title($count ? "Прив'язано броней: {$count}" : 'Нових броней із цим номером немає')
                            ->success()
                            ->send();
                    }),

                EditAction::make(),
            ]);
    }
}
