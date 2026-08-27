<?php

namespace App\Filament\Resources;

use App\Traits\RestrictCookAccess;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class UserResource extends Resource
{
    use RestrictCookAccess;
protected static ?string $model = User::class;

    protected static ?string $navigationGroup = 'Система';
    protected static ?int $navigationSort = 2;
    protected static ?string $navigationLabel = 'Користувачі (Доступи)'; 
    protected static ?string $pluralModelLabel = 'Користувачі'; 
    protected static ?string $modelLabel = 'Користувач';

    // 🔒 ЗАХИСТ: Тільки Адмін
    public static function canViewAny(): bool
    {
        return auth()->user()->role === 'admin';
    }

    // 🔒 ЗАХИСТ: закриває ВСІ сторінки ресурсу (list/create/edit) — інакше трейт
    // RestrictCookAccess пускає менеджера на /admin/users/{id}/edit напряму
    public static function canAccess(): bool
    {
        return auth()->user()->role === 'admin';
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label("Ім'я")
                    ->required(),
                
                Forms\Components\TextInput::make('email')
                    ->email()
                    ->required()
                    ->label('Email'),

                Forms\Components\TextInput::make('password')
                    ->label('Пароль')
                    ->password()
                    ->dehydrateStateUsing(fn ($state) => \Illuminate\Support\Facades\Hash::make($state))
                    ->dehydrated(fn ($state) => filled($state))
                    ->required(fn (string $context): bool => $context === 'create'),

                Forms\Components\Select::make('role')
                    ->label('Посада')
                    ->options([
                        'admin' => 'Адміністратор (Власник)',
                        'manager' => 'Менеджер',
                        'cook' => 'Кухар',
                    ])
                    ->required()
                    ->default('admin'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label("Ім'я")
                    ->searchable(),
                Tables\Columns\TextColumn::make('email')
                    ->label('Email'),
                Tables\Columns\TextColumn::make('role')
                    ->label('Посада')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'admin' => 'danger',
                        'manager' => 'primary',
                        'cook' => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'admin' => 'Адміністратор',
                        'manager' => 'Менеджер',
                        'cook' => 'Кухар',
                        default => $state,
                    }),
            ])
                ->actions([
                    Tables\Actions\EditAction::make()->label('')->tooltip('Змінити'),
                    Tables\Actions\DeleteAction::make()->label('')->tooltip('Видалити'),
                ])
                ->bulkActions([
                    Tables\Actions\BulkActionGroup::make([
                        Tables\Actions\DeleteBulkAction::make(), // Масове видалення (опціонально)
                    ]),
                ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}