<?php

namespace App\Filament\Resources\OrderResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use Illuminate\Database\Eloquent\Model;

class CalendarRelationManager extends RelationManager
{
    protected static string $relationship = 'calendar'; // Назва нашого фейкового зв'язку
    protected static ?string $title = 'Календар доставок';
    protected static ?string $icon = 'heroicon-o-calendar';

    // Цей метод потрібен, щоб Filament дозволив показати "вкладку" для одного запису
    public function isReadOnly(): bool 
    {
        return false;
    }

public function table(Table $table): Table
    {
        return $table
            ->columns([]) // Без колонок
            ->paginated(false) // Без сторінок
            ->headerActions([]) // Без кнопок
            ->content(function () {
                // Повертаємо View (файл), а не HtmlString
                return view('filament.resources.orders.calendar', [
                    'order' => $this->getOwnerRecord(),
                ]);
            });
    }
}