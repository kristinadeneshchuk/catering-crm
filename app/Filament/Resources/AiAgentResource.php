<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AiAgentResource\Pages;
use App\Filament\Resources\AiAgentResource\RelationManagers;
use App\Models\AiAgent;
use App\Models\AiProposal;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/** ШІ-агенти: роль, правила, задачі, звіти, памʼять. Лише супер адмін. */
class AiAgentResource extends Resource
{
    protected static ?string $model = AiAgent::class;
    protected static ?string $navigationIcon = 'heroicon-o-cpu-chip';
    protected static ?string $navigationLabel = 'ШІ-агенти';
    protected static ?string $modelLabel = 'ШІ-агент';
    protected static ?string $pluralModelLabel = 'ШІ-агенти';
    protected static ?string $navigationGroup = 'ШІ';
    protected static ?int $navigationSort = 10;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->isSuperAdmin();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->schema([
                Forms\Components\TextInput::make('name')->label('Назва')->required(),
                Forms\Components\TextInput::make('slug')->label('Код (для токена)')->required()->alphaDash()->unique(ignoreRecord: true),
                Forms\Components\Textarea::make('role')->label('Що робить')->rows(2)->columnSpanFull(),
                Forms\Components\Textarea::make('instructions')->label('Правила власника (агент читає на старті)')->rows(12)->columnSpanFull(),
                Forms\Components\CheckboxList::make('allowed_types')->label('Що може пропонувати')->options(AiProposal::TYPES),
                Forms\Components\TextInput::make('schedule')->label('Розклад')->placeholder('напр. щочетверга о 10:00'),
                Forms\Components\Toggle::make('is_active')->label('Активний')->default(true),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Агент')->weight('bold')->description(fn (AiAgent $a) => $a->role),
                Tables\Columns\IconColumn::make('is_active')->label('Активний')->boolean(),
                Tables\Columns\TextColumn::make('last_seen_at')->label('Був на звʼязку')->since()->placeholder('ще ні'),
                Tables\Columns\TextColumn::make('open_tasks')->label('Задачі')
                    ->state(fn (AiAgent $a) => $a->tasks()->whereIn('status', ['new', 'in_progress'])->count()),
                Tables\Columns\TextColumn::make('pending')->label('Чекають погодження')
                    ->state(fn (AiAgent $a) => $a->proposals()->where('status', 'pending')->count())
                    ->badge()->color(fn ($state) => $state ? 'warning' : 'gray'),
            ])
            ->actions([
                Tables\Actions\Action::make('token')
                    ->label('')->tooltip('Новий токен для API')->icon('heroicon-o-key')
                    ->requiresConfirmation()
                    ->modalDescription('Старий токен перестане діяти. Новий покажемо один раз — передайте його агенту.')
                    ->action(fn (AiAgent $a) => Notification::make()->title('Токен агента «' . $a->name . '»')
                        ->body($a->issueToken())->persistent()->success()->send()),
                Tables\Actions\EditAction::make()->label('')->tooltip('Відкрити'),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\TasksRelationManager::class,
            RelationManagers\ReportsRelationManager::class,
            RelationManagers\MemoryRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListAiAgents::route('/'),
            'create' => Pages\CreateAiAgent::route('/create'),
            'edit'   => Pages\EditAiAgent::route('/{record}/edit'),
        ];
    }
}
