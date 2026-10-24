<?php

namespace App\Filament\Resources\AiAgentTaskResource\Pages;

use App\Filament\Resources\AiAgentTaskResource;
use App\Services\Agents\TaskDesk;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

/** Тред задачі: хід роботи агента, його питання, відповіді людей, пропозиції. */
class ViewAiAgentTask extends ViewRecord
{
    protected static string $resource = AiAgentTaskResource::class;

    protected static string $view = 'filament.ai.view-task';

    public string $reply = '';

    public function getTitle(): string
    {
        return "#{$this->record->id} · {$this->record->title}";
    }

    /** Відповідь агенту з поля під тредом. */
    public function sendReply(): void
    {
        $text = trim($this->reply);
        if ($text === '') return;
        app(TaskDesk::class)->answer($this->record, auth()->user(), $text);
        $this->reply = '';
        $this->record->refresh();
        Notification::make()->title('Відповідь передано агенту')->success()->send();
    }

    protected function getHeaderActions(): array
    {
        $desk = app(TaskDesk::class);

        return [
            Actions\Action::make('restart')->label('Перезапустити')->icon('heroicon-o-arrow-path')->color('gray')
                ->visible(fn () => in_array($this->record->status, ['failed', 'done', 'cancelled'], true))
                ->form([Forms\Components\Textarea::make('comment')->label('Що врахувати (необовʼязково)')->rows(3)])
                ->action(function (array $data) use ($desk) {
                    $desk->restart($this->record, auth()->user(), $data['comment'] ?? null);
                    $this->record->refresh();
                }),
            Actions\Action::make('cancel')->label('Скасувати')->icon('heroicon-o-x-mark')->color('danger')
                ->visible(fn () => $this->record->isOpen())
                ->requiresConfirmation()
                ->action(function () use ($desk) {
                    $desk->cancel($this->record, auth()->user());
                    $this->record->refresh();
                }),
        ];
    }
}
