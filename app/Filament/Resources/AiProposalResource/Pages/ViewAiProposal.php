<?php

namespace App\Filament\Resources\AiProposalResource\Pages;

use App\Filament\Resources\AiProposalResource;
use App\Models\AiProposal;
use App\Services\Agents\ProposalDesk;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewAiProposal extends ViewRecord
{
    protected static string $resource = AiProposalResource::class;

    protected static string $view = 'filament.ai.view-proposal';

    public function getTitle(): string
    {
        return $this->record->title;
    }

    protected function getHeaderActions(): array
    {
        $pending = fn () => $this->record->isPending();
        $desk = app(ProposalDesk::class);

        return [
            Actions\Action::make('approve')->label('Погодити')->color('success')->icon('heroicon-o-check')
                ->visible($pending)->requiresConfirmation()
                ->modalDescription('CRM одразу виконає пропозицію (для меню — створить страви й персональне меню).')
                ->action(function () use ($desk) {
                    $answer = $desk->approve($this->record, auth()->user());
                    Notification::make()->title($answer)->{$this->record->fresh()->status === 'applied' ? 'success' : 'danger'}()->send();
                    $this->record->refresh();
                }),
            Actions\Action::make('force')->label('Надіслати все одно')->color('warning')->icon('heroicon-o-paper-airplane')
                ->visible(fn () => $this->record->status === 'changes_requested' && ($this->record->apply_result['code'] ?? null) === 'stale')
                ->requiresConfirmation()
                ->modalDescription('Клієнт написав ще після того, як агент склав текст. Надіслати попри це?')
                ->action(function () use ($desk) {
                    Notification::make()->title($desk->approve($this->record, auth()->user(), true))->send();
                    $this->record->refresh();
                }),
                        Actions\Action::make('changes')->label('Правки')->color('info')->icon('heroicon-o-pencil')
                ->visible($pending)
                ->form([Forms\Components\Textarea::make('comment')->label('Що змінити')->required()->rows(4)])
                ->action(function (array $data) use ($desk) {
                    Notification::make()->title($desk->reject($this->record, auth()->user(), $data['comment'], true))->send();
                    $this->record->refresh();
                }),
            Actions\Action::make('reject')->label('Відхилити')->color('danger')->icon('heroicon-o-x-mark')
                ->visible($pending)
                ->form([Forms\Components\Textarea::make('comment')->label('Чому (необовʼязково)')->rows(2)])
                ->action(function (array $data) use ($desk) {
                    Notification::make()->title($desk->reject($this->record, auth()->user(), $data['comment'] ?? null))->send();
                    $this->record->refresh();
                }),
        ];
    }

    /** Дані для перегляду меню: назви страв, прийомів, продуктів. */
    public function menuView(): array
    {
        $p = $this->record->payload;
        $dishIds = collect($p['days'] ?? [])->flatMap(fn ($d) => collect($d['meals'] ?? [])->pluck('dish_id'))->filter()->unique();
        $dishes = \App\Models\Dish::whereIn('id', $dishIds)->pluck('name', 'id');
        $meals = \App\Models\MealType::orderBy('sort_order')->pluck('name', 'id');
        $newByRef = collect($p['new_dishes'] ?? [])->keyBy('ref');
        $ingIds = $newByRef->flatMap(fn ($d) => collect($d['ingredients'] ?? [])->pluck('ingredient_id'))->filter();
        $childIds = $newByRef->flatMap(fn ($d) => collect($d['ingredients'] ?? [])->pluck('child_dish_id'))->filter();

        return [
            'days'      => $p['days'] ?? [],
            'dishes'    => $dishes,
            'meals'     => $meals,
            'new'       => $newByRef,
            'ingNames'  => \App\Models\Ingredient::whereIn('id', $ingIds)->pluck('name', 'id'),
            'pfNames'   => \App\Models\Dish::whereIn('id', $childIds)->pluck('name', 'id'),
        ];
    }
}
