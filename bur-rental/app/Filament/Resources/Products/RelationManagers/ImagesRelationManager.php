<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Models\ProductImage;
use App\Services\ProductImageSources;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Throwable;

/**
 * Фото товару. Перше в списку — головне: воно йде в картку каталогу,
 * у прев'ю посилань і в розмітку Product для Google. Порядок — перетягуванням.
 */
class ImagesRelationManager extends RelationManager
{
    protected static string $relationship = 'images';

    protected static ?string $title = 'Фото';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            FileUpload::make('path')
                ->label('Фото')
                ->image()
                ->disk('public')
                ->directory(fn () => 'products/'.$this->getOwnerRecord()->id)
                ->maxSize(15 * 1024)
                ->helperText('JPEG, PNG або WebP. Краще квадратне, на білому тлі, від 1200 px. Стиснення в WebP — автоматично.')
                ->required(),

            TextInput::make('alt')
                ->label('Підпис для пошуковиків')
                ->placeholder('Порожньо — бренд і назва товару')
                ->maxLength(200),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->reorderable('position')
            ->defaultSort('position')
            ->columns([
                ImageColumn::make('preview')
                    ->label('')
                    ->state(fn (ProductImage $record) => $record->url('sm'))
                    ->imageSize(72),

                TextColumn::make('alt')
                    ->label('Підпис')
                    ->placeholder(fn () => 'авто: '.$this->getOwnerRecord()->name),

                TextColumn::make('width')
                    ->label('Розмір')
                    ->state(fn (ProductImage $record) => $record->width ? "{$record->width}×{$record->height}" : '—'),

                TextColumn::make('source_url')
                    ->label('Джерело')
                    ->placeholder('завантажено вручну')
                    ->limit(40)
                    ->url(fn (ProductImage $record) => $record->source_url, shouldOpenInNewTab: true),
            ])
            ->headerActions([
                CreateAction::make()->label('Завантажити фото'),

                Action::make('fromUrl')
                    ->label('Додати за посиланням')
                    ->icon('heroicon-o-link')
                    ->schema([
                        TextInput::make('url')
                            ->label('Посилання')
                            ->helperText('Сторінка товару на сайті виробника або пряме посилання на фото.')
                            ->url()
                            ->required(),
                    ])
                    ->action(function (array $data) {
                        try {
                            app(ProductImageSources::class)->import($this->getOwnerRecord(), $data['url']);
                            Notification::make()->title('Фото додано')->success()->send();
                        } catch (Throwable $e) {
                            Notification::make()->title('Не вдалося')->body($e->getMessage())->danger()->send();
                        }
                    }),
            ])
            ->recordActions([
                EditAction::make()->label('Підпис'),
                DeleteAction::make(),
            ])
            ->emptyStateHeading('Фото ще немає')
            ->emptyStateDescription('На сайті поки стоїть заглушка. Перше завантажене фото стане головним.');
    }
}
