<?php

namespace App\Filament\Resources\SettingResource\Pages;

use App\Filament\Resources\SettingResource;
use App\Models\Setting;
use App\Services\TurboSmsService;
use Filament\Actions;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\HtmlString;

class ListSettings extends ListRecords
{
    protected static string $resource = SettingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),

            // Налаштування SMS-шлюзу. Живуть тут, а не на сторінці Логістики:
            // там кнопка потрібна раз на життя, а користуються нею з налаштувань.
            Actions\Action::make('sms_settings')
                ->label('Налаштування SMS')
                ->icon('heroicon-o-chat-bubble-left-right')
                ->color('gray')
                ->modalHeading('TurboSMS — сповіщення клієнтів про курʼєра')
                ->modalDescription('Ці дані використовує кнопка «Сповістити клієнтів» на сторінці Логістики.')
                ->modalSubmitActionLabel('Зберегти')
                ->form([
                    Placeholder::make('balance')
                        ->label('Баланс TurboSMS')
                        ->content(function () {
                            $service = app(TurboSmsService::class);

                            if (! $service->isConfigured()) {
                                return 'Спочатку вкажіть токен і альфа-імʼя.';
                            }

                            $balance = $service->balance();

                            return $balance === null
                                ? 'Не вдалося отримати баланс — перевірте токен.'
                                : number_format($balance, 2, ',', ' ') . ' ₴';
                        }),

                    TextInput::make(TurboSmsService::KEY_TOKEN)
                        ->label('API-токен')
                        ->password()
                        ->revealable()
                        ->autocomplete(false)
                        ->helperText('Кабінет TurboSMS → Розробникам → API-токен')
                        ->default(fn () => Setting::where('key', TurboSmsService::KEY_TOKEN)->value('value')),

                    TextInput::make(TurboSmsService::KEY_SENDER)
                        ->label('Альфа-імʼя відправника')
                        ->maxLength(25)
                        ->helperText('Має бути зареєстроване та підтверджене в кабінеті TurboSMS')
                        ->default(fn () => Setting::where('key', TurboSmsService::KEY_SENDER)->value('value')),

                    Textarea::make(TurboSmsService::KEY_TEMPLATE)
                        ->label('Текст SMS')
                        ->rows(4)
                        ->helperText(new HtmlString(
                            'Плейсхолдери: <code>{courier}</code> — імʼя курʼєра, <code>{phone}</code> — його телефон, '
                            . '<code>{car}</code> — номер авто, <code>{client}</code> — імʼя клієнта.<br>'
                            . 'Кирилицею в одну SMS вміщується 70 символів — довший текст тарифікується як кілька.'
                        ))
                        ->default(fn () => Setting::where('key', TurboSmsService::KEY_TEMPLATE)->value('value')
                            ?: TurboSmsService::DEFAULT_TEMPLATE),
                ])
                ->action(function (array $data) {
                    foreach ([TurboSmsService::KEY_TOKEN, TurboSmsService::KEY_SENDER, TurboSmsService::KEY_TEMPLATE] as $key) {
                        if (array_key_exists($key, $data)) {
                            Setting::updateOrCreate(['key' => $key], ['value' => $data[$key]]);
                        }
                    }

                    Notification::make()->title('Налаштування SMS збережено')->success()->send();
                }),
        ];
    }
}
