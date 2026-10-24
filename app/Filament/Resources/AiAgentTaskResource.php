<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AiAgentTaskResource\Pages;
use App\Models\AiAgent;
use App\Models\AiAgentTask;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * «Задачі ШІ»: що менеджери доручили агентам, хід роботи, питання агентів,
 * пропозиції на погодження (docs/tz-ai-agents-in-crm.md §3.1).
 */
class AiAgentTaskResource extends Resource
{
    protected static ?string $model = AiAgentTask::class;
    protected static ?string $navigationIcon = 'heroicon-o-sparkles';
    protected static ?string $navigationLabel = 'Задачі ШІ';
    protected static ?string $modelLabel = 'Задача ШІ';
    protected static ?string $pluralModelLabel = 'Задачі ШІ';
    protected static ?string $navigationGroup = 'ШІ';
    protected static ?int $navigationSort = 4;
    protected static ?string $slug = 'ai-agent-tasks';

    public static function canAccess(): bool
    {
        $u = auth()->user();

        return $u && ($u->isAdmin() || $u->isManager());
    }

    public static function canCreate(): bool { return false; }
    public static function canEdit(Model $record): bool { return false; }
    public static function canDelete(Model $record): bool { return false; }

    public static function getNavigationBadge(): ?string
    {
        try {
            $n = AiAgentTask::where('status', 'waiting_human')->count();
        } catch (\Throwable) {
            return null; // до міграції
        }

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string { return 'warning'; }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->poll('10s')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['agent', 'client', 'creator']))
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('№'),
                Tables\Columns\TextColumn::make('agent.name')->label('Агент'),
                Tables\Columns\TextColumn::make('title')->label('Що')->wrap()->searchable()
                    ->description(fn (AiAgentTask $t) => $t->status === 'failed' ? $t->error : null),
                Tables\Columns\TextColumn::make('client.name')->label('Клієнт')->placeholder('—')->searchable(),
                Tables\Columns\TextColumn::make('status')->label('Статус')->badge()
                    ->formatStateUsing(fn ($state) => AiAgentTask::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => ['new' => 'gray', 'in_progress' => 'info', 'waiting_human' => 'warning', 'done' => 'success', 'failed' => 'danger', 'cancelled' => 'gray'][$state] ?? 'gray'),
                Tables\Columns\TextColumn::make('pending')->label('На погодженні')->badge()->color('warning')
                    ->state(fn (AiAgentTask $t) => $t->proposals()->where('status', 'pending')->count() ?: null)->placeholder(''),
                Tables\Columns\TextColumn::make('due_at')->label('Дедлайн')->dateTime('d.m H:i')->placeholder('—')
                    ->color(fn (AiAgentTask $t) => $t->due_at && $t->isOpen() && $t->due_at->isPast() ? 'danger' : null),
                Tables\Columns\TextColumn::make('creator.name')->label('Поставив')->placeholder('—'),
                Tables\Columns\TextColumn::make('cost_usd')->label('$')->formatStateUsing(fn ($state) => (float) $state > 0 ? number_format((float) $state, 2) : '')
                    ->visible(fn () => auth()->user()?->isAdmin()),
                Tables\Columns\TextColumn::make('created_at')->label('Коли')->dateTime('d.m H:i'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('Статус')->options(AiAgentTask::STATUSES)->multiple()
                    ->default(AiAgentTask::OPEN),
                Tables\Filters\SelectFilter::make('ai_agent_id')->label('Агент')->options(fn () => AiAgent::pluck('name', 'id')),
                Tables\Filters\Filter::make('mine')->label('Поставлені мною')->query(fn (Builder $query) => $query->where('created_by', auth()->id())),
            ])
            ->actions([Tables\Actions\ViewAction::make()->label('Відкрити')]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAiAgentTasks::route('/'),
            'view'  => Pages\ViewAiAgentTask::route('/{record}'),
        ];
    }
}
