<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AiProposalResource\Pages;
use App\Models\AiProposal;
use App\Services\Agents\ProposalDesk;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Погодження пропозицій ШІ-агентів. Бачать призначені менеджер і власник. */
class AiProposalResource extends Resource
{
    protected static ?string $model = AiProposal::class;
    protected static ?string $navigationIcon = 'heroicon-o-check-badge';
    protected static ?string $navigationLabel = 'Погодження';
    protected static ?string $modelLabel = 'Пропозиція';
    protected static ?string $pluralModelLabel = 'Погодження';
    protected static ?string $navigationGroup = 'ШІ';
    protected static ?int $navigationSort = 5;

    public static function canAccess(): bool
    {
        return app(ProposalDesk::class)->mayApprove(auth()->user());
    }

    public static function canCreate(): bool { return false; }
    public static function canEdit(Model $record): bool { return false; }
    public static function canDelete(Model $record): bool { return false; }

    public static function getNavigationBadge(): ?string
    {
        $n = AiProposal::where('status', 'pending')->count();

        return $n ? (string) $n : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('Коли')->dateTime('d.m H:i'),
                Tables\Columns\TextColumn::make('agent.name')->label('Агент'),
                Tables\Columns\TextColumn::make('type')->label('Тип')->formatStateUsing(fn ($s) => AiProposal::TYPES[$s] ?? $s),
                Tables\Columns\TextColumn::make('title')->label('Що')->description(fn (AiProposal $p) => $p->summary)->wrap(),
                Tables\Columns\TextColumn::make('status')->label('Статус')->badge()
                    ->formatStateUsing(fn ($s) => AiProposal::STATUSES[$s] ?? $s)
                    ->color(fn ($s) => ['pending' => 'warning', 'applied' => 'success', 'failed' => 'danger', 'rejected' => 'gray', 'changes_requested' => 'info'][$s] ?? 'gray'),
                Tables\Columns\TextColumn::make('reviewer.name')->label('Хто')->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('Статус')->options(AiProposal::STATUSES)->default('pending'),
                Tables\Filters\SelectFilter::make('type')->label('Тип')->options(AiProposal::TYPES),
            ])
            ->actions([Tables\Actions\ViewAction::make()->label('Відкрити')]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAiProposals::route('/'),
            'view'  => Pages\ViewAiProposal::route('/{record}'),
        ];
    }
}
