<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MessengerAccountResource\Pages;
use App\Models\MessengerAccount;
use App\Services\Messenger\ChannelDriverManager;
use App\Traits\RestrictCookAccess;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class MessengerAccountResource extends Resource
{
    use RestrictCookAccess;

    protected static ?string $model = MessengerAccount::class;

    protected static ?string $navigationGroup = 'Система';
    protected static ?string $navigationLabel = 'Месенджер-акаунти';
    protected static ?string $modelLabel       = 'Месенджер-акаунт';
    protected static ?string $pluralModelLabel = 'Месенджер-акаунти';
    protected static ?int    $navigationSort   = 50;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('channel')
                ->label('Канал')
                ->required()
                ->live()
                ->options([
                    MessengerAccount::CHANNEL_TELEGRAM  => 'Telegram',
                    MessengerAccount::CHANNEL_INSTAGRAM => 'Instagram',
                    MessengerAccount::CHANNEL_VIBER     => 'Viber',
                ])
                ->disabledOn('edit'),

            Forms\Components\TextInput::make('display_name')
                ->label('Назва акаунта (для UI)')
                ->placeholder('Напр., "Viber компанії", "Instagram avocado.ua"')
                ->required()
                ->maxLength(255),

            // Один акаунт = один бренд. Конструктор замовлення в чаті бере
            // проєкт звідси, щоб менеджер не обирав його щоразу руками.
            Forms\Components\Select::make('project')
                ->label('Бренд')
                ->options(fn () => \App\Models\Project::where('is_active', true)
                    ->orderBy('name')->pluck('name', 'slug'))
                ->helperText('Замовлення з цього чату підуть у цей бренд. У картці бренд можна змінити вручну.')
                ->searchable()
                ->preload(),

            // ─────────── VIBER ───────────
            Forms\Components\Section::make('Viber')
                ->visible(fn (Get $get) => $get('channel') === MessengerAccount::CHANNEL_VIBER)
                ->schema([
                    Forms\Components\TextInput::make('credentials.auth_token')
                        ->label('Auth Token з partners.viber.com')
                        ->placeholder('4831b3bd0d3a18a4-fa15bed94e8a5b15-xxxxx')
                        ->helperText('Знаходиться в адмінці Public Account → API. Після збереження натисни «Підключити».')
                        ->password()
                        ->revealable()
                        ->required(fn (string $operation) => $operation === 'create')
                        ->dehydrated(fn ($state) => filled($state)),
                ])
                ->columnSpanFull(),

            // ─────────── INSTAGRAM ───────────
            Forms\Components\Section::make('Instagram')
                ->visible(fn (Get $get) => $get('channel') === MessengerAccount::CHANNEL_INSTAGRAM)
                ->schema([
                    Forms\Components\Placeholder::make('instagram_help')
                        ->label('')
                        ->content('Збережи акаунт, потім натисни «Авторизувати через Instagram» — CRM перенаправить тебе на instagram.com для надання прав. Працює через нову Instagram Login API (без Facebook Page).'),
                ])
                ->columnSpanFull(),

            // ─────────── TELEGRAM (поки заглушка) ───────────
            Forms\Components\Section::make('Telegram')
                ->visible(fn (Get $get) => $get('channel') === MessengerAccount::CHANNEL_TELEGRAM)
                ->schema([
                    Forms\Components\TextInput::make('credentials.bot_token')
                        ->label('Токен бота з @BotFather')
                        ->placeholder('7123456789:AAF...')
                        ->helperText('Створи бота в @BotFather, встав сюди токен і збережи. Далі — кнопка «Підключити».')
                        ->password()
                        ->revealable(),
                    Forms\Components\Placeholder::make('telegram_help')
                        ->label('')
                        ->content(new \Illuminate\Support\HtmlString(
                            '<b>Як під\'єднати бізнес-акаунт бренду:</b><br>'
                            .'1. Збережи акаунт із токеном і натисни «Підключити» — CRM зареєструє webhook.<br>'
                            .'2. У Telegram того номера, на який пишуть клієнти: <b>Налаштування → Telegram Business → Чат-боти</b>.<br>'
                            .'3. Додай туди свого бота і дозволь йому відповідати.<br>'
                            .'4. Акаунт у CRM стане активним сам — щойно Telegram підтвердить підключення.<br><br>'
                            .'<span style="opacity:.75">Потрібен Telegram Premium на бізнес-акаунті. Клієнти пишуть як завжди — на живий акаунт, не боту.</span>'
                        )),
                ])
                ->columnSpanFull(),

            // ─────────── СТАТУС / ПОМИЛКА ───────────
            Forms\Components\Select::make('status')
                ->label('Статус')
                ->required()
                ->options([
                    MessengerAccount::STATUS_ACTIVE   => 'Активний',
                    MessengerAccount::STATUS_INACTIVE => 'Неактивний',
                    MessengerAccount::STATUS_EXPIRED  => 'Сесія завершена',
                    MessengerAccount::STATUS_ERROR    => 'Помилка',
                ])
                ->default(MessengerAccount::STATUS_INACTIVE)
                ->disabled(),

            Forms\Components\Textarea::make('last_error')
                ->label('Остання помилка')
                ->rows(3)
                ->columnSpanFull()
                ->disabled()
                ->visible(fn ($record) => $record && $record->last_error),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('channel')
                    ->label('Канал')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        MessengerAccount::CHANNEL_TELEGRAM  => 'Telegram',
                        MessengerAccount::CHANNEL_INSTAGRAM => 'Instagram',
                        MessengerAccount::CHANNEL_VIBER     => 'Viber',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        MessengerAccount::CHANNEL_TELEGRAM  => 'info',
                        MessengerAccount::CHANNEL_INSTAGRAM => 'danger',
                        MessengerAccount::CHANNEL_VIBER     => 'primary',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('display_name')
                    ->label('Назва')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('external_account_id')
                    ->label('ID у каналі')
                    ->fontFamily('mono')
                    ->copyable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        MessengerAccount::STATUS_ACTIVE   => 'Активний',
                        MessengerAccount::STATUS_INACTIVE => 'Неактивний',
                        MessengerAccount::STATUS_EXPIRED  => 'Сесія завершена',
                        MessengerAccount::STATUS_ERROR    => 'Помилка',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        MessengerAccount::STATUS_ACTIVE   => 'success',
                        MessengerAccount::STATUS_INACTIVE => 'gray',
                        MessengerAccount::STATUS_EXPIRED  => 'warning',
                        MessengerAccount::STATUS_ERROR    => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('last_synced_at')
                    ->label('Остання синхронізація')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('connectedBy.name')
                    ->label('Підключив')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('channel')
                    ->label('Канал')
                    ->options([
                        MessengerAccount::CHANNEL_TELEGRAM  => 'Telegram',
                        MessengerAccount::CHANNEL_INSTAGRAM => 'Instagram',
                        MessengerAccount::CHANNEL_VIBER     => 'Viber',
                    ]),
                Tables\Filters\SelectFilter::make('status')
                    ->label('Статус')
                    ->options([
                        MessengerAccount::STATUS_ACTIVE   => 'Активний',
                        MessengerAccount::STATUS_INACTIVE => 'Неактивний',
                        MessengerAccount::STATUS_EXPIRED  => 'Сесія завершена',
                        MessengerAccount::STATUS_ERROR    => 'Помилка',
                    ]),
            ])
            ->actions([
                // ─── Універсальна «Підключити» (Viber і Telegram) ───
                Tables\Actions\Action::make('connect')
                    ->label('Підключити')
                    ->icon('heroicon-o-bolt')
                    ->color('success')
                    ->visible(fn (MessengerAccount $record) => in_array($record->channel, [
                        MessengerAccount::CHANNEL_VIBER,
                        MessengerAccount::CHANNEL_TELEGRAM,
                    ], true))
                    ->requiresConfirmation()
                    ->modalHeading(fn (MessengerAccount $record) => $record->channel === MessengerAccount::CHANNEL_TELEGRAM
                        ? 'Підключити Telegram-бота'
                        : 'Підключити Viber-акаунт')
                    ->modalDescription(fn (MessengerAccount $record) => $record->channel === MessengerAccount::CHANNEL_TELEGRAM
                        ? 'CRM перевірить токен і зареєструє webhook. Після цього додай бота у Telegram → Налаштування → Telegram Business → Чат-боти.'
                        : 'CRM перевірить токен у Viber API і зареєструє webhook на цей сервер.')
                    ->action(function (MessengerAccount $record, ChannelDriverManager $drivers) {
                        try {
                            $drivers->for($record)->connect($record);

                            $record->refresh();

                            Notification::make()
                                ->title('Webhook зареєстровано')
                                // Telegram активується не тут: акаунт стане активним,
                                // коли власник додасть бота в Telegram Business.
                                ->body($record->status === MessengerAccount::STATUS_ACTIVE
                                    ? 'Акаунт активний.'
                                    : ($record->last_error ?: 'Залишився крок на боці месенджера.'))
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            $record->update([
                                'status'     => MessengerAccount::STATUS_ERROR,
                                'last_error' => mb_substr($e->getMessage(), 0, 1000),
                            ]);

                            Notification::make()
                                ->title('Помилка підключення')
                                ->body($e->getMessage())
                                ->danger()
                                ->persistent()
                                ->send();
                        }
                    }),

                // ─── Instagram OAuth (через Instagram Login API) ───
                Tables\Actions\Action::make('authorizeInstagram')
                    ->label('Авторизувати через Instagram')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('danger')
                    ->visible(fn (MessengerAccount $record) => $record->channel === MessengerAccount::CHANNEL_INSTAGRAM)
                    ->url(fn (MessengerAccount $record) => route('messenger.instagram.oauth.start', $record)),

                // ─── Відключити ───
                Tables\Actions\Action::make('disconnect')
                    ->label('Відключити')
                    ->icon('heroicon-o-no-symbol')
                    ->color('warning')
                    ->visible(fn (MessengerAccount $record) => $record->status === MessengerAccount::STATUS_ACTIVE)
                    ->requiresConfirmation()
                    ->modalHeading('Відключити акаунт?')
                    ->modalDescription('Webhook буде знятий. Існуючі чати залишаться в БД, але нові повідомлення приходити не будуть.')
                    ->action(function (MessengerAccount $record, ChannelDriverManager $drivers) {
                        try {
                            $drivers->for($record)->disconnect($record);
                            $record->update(['status' => MessengerAccount::STATUS_INACTIVE]);

                            Notification::make()->title('Відключено')->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Помилка')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Tables\Actions\EditAction::make()->label('')->tooltip('Змінити'),
                Tables\Actions\DeleteAction::make()->label('')->tooltip('Видалити'),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListMessengerAccounts::route('/'),
            'create' => Pages\CreateMessengerAccount::route('/create'),
            'edit'   => Pages\EditMessengerAccount::route('/{record}/edit'),
        ];
    }
}
