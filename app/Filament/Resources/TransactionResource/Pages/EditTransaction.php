<?php

namespace App\Filament\Resources\TransactionResource\Pages;

use App\Filament\Resources\TransactionResource;
use Filament\Actions;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Form;
use Filament\Resources\Pages\EditRecord;

class EditTransaction extends EditRecord
{
    protected static string $resource = TransactionResource::class;

    public function mount(int | string $record): void
    {
        parent::mount($record);

        // Менеджеру взагалі не даємо відкрити редагування минулих транзакцій.
        if (! TransactionResource::canManagerEdit($this->getRecord())) {
            abort(403, 'Тільки адміністратор може редагувати транзакції, старіші за сьогодні.');
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    /**
     * Додаємо секцію «Причина правки» до оригінальної форми ресурсу.
     * Для менеджера обовʼязкова, для адміна — на розсуд.
     */
    public function form(Form $form): Form
    {
        $form = TransactionResource::form($form);
        $isManager = auth()->user()?->role === 'manager';

        $reasonSection = Section::make('Причина правки')
            ->description($isManager
                ? 'Обовʼязково — коротко поясни, чому редагуєш.'
                : 'Необовʼязково. Якщо заповниш — дописується у коментар транзакції.')
            ->schema([
                Textarea::make('editReason')
                    ->label('')
                    ->placeholder('напр. «одрук — було 500, треба 5000»')
                    ->required($isManager)
                    ->rows(2)
                    ->maxLength(500)
                    ->dehydrated(false),
            ]);

        return $form->schema([...$form->getComponents(), $reasonSection]);
    }

    /** Дописуємо причину правки у comment перед збереженням. */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $reason = trim((string) ($this->data['editReason'] ?? ''));
        if ($reason === '') {
            return $data;
        }

        $stamp   = now()->format('d.m H:i');
        $userNm  = auth()->user()?->name ?? '?';
        $note    = "[правка {$stamp}, {$userNm}: {$reason}]";

        $existing = trim((string) ($data['comment'] ?? ''));
        $data['comment'] = $existing === '' ? $note : ($existing . "\n" . $note);

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
