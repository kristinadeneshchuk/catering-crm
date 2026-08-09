<?php

namespace App\Filament\Pages;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Models\UnavailableDate;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * Календар зайнятості парку: рядок — модель, стовпчик — день.
 *
 * Це той самий вигляд, який менеджер малює собі в зошиті. Клік по вільній
 * клітинці блокує день на сервіс, клік по сервісному — звільняє. Дні під
 * реальною орендою звідси не чіпаються: їх звільняє тільки приймання техніки,
 * інакше можна «звільнити» інструмент, який фізично в руках у клієнта.
 */
class AvailabilityBoard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Наявність';

    protected static ?string $navigationLabel = 'Календар зайнятості';

    protected static ?string $title = 'Календар зайнятості';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.availability-board';

    public ?int $branchId = null;

    public ?int $categoryId = null;

    public string $startDate = '';

    public int $daysToShow = 21;

    public function mount(): void
    {
        $this->branchId = Branch::orderBy('position')->value('id');
        $this->startDate = Carbon::today()->toDateString();
    }

    public function shiftDates(int $days): void
    {
        $this->startDate = Carbon::parse($this->startDate)->addDays($days)->toDateString();
    }

    public function today(): void
    {
        $this->startDate = Carbon::today()->toDateString();
    }

    /** @return Collection<int, Branch> */
    public function getBranchesProperty(): Collection
    {
        return Branch::with('city')->orderBy('position')->get();
    }

    /** @return Collection<int, Category> */
    public function getCategoriesProperty(): Collection
    {
        return Category::roots()->get();
    }

    /** @return Collection<int, Carbon> */
    public function getDaysProperty(): Collection
    {
        $start = Carbon::parse($this->startDate);

        return collect(range(0, $this->daysToShow - 1))->map(fn (int $i) => $start->copy()->addDays($i));
    }

    /** @return Collection<int, Product> */
    public function getProductsProperty(): Collection
    {
        if (! $this->branchId) {
            return collect();
        }

        return Product::query()
            ->with(['brand', 'unavailableDates' => fn ($q) => $q
                ->where('branch_id', $this->branchId)
                ->whereBetween('date', [$this->days->first(), $this->days->last()])])
            ->whereHas('branches', fn ($q) => $q->whereKey($this->branchId))
            ->when($this->categoryId, fn ($q) => $q->where('category_id', $this->categoryId))
            ->orderBy('name')
            ->get();
    }

    /** Стан клітинки: free | rented | service. */
    public function cellState(Product $product, string $date): string
    {
        $row = $product->unavailableDates->firstWhere(
            fn (UnavailableDate $d) => $d->date->toDateString() === $date
        );

        return $row?->reason ?? 'free';
    }

    public function toggle(int $productId, string $date): void
    {
        $existing = UnavailableDate::where('product_id', $productId)
            ->where('branch_id', $this->branchId)
            ->whereDate('date', $date)
            ->first();

        if ($existing?->reason === 'rented') {
            Notification::make()
                ->title('День зайнятий орендою')
                ->body('Звільнити його можна тільки прийманням техніки в бронюванні.')
                ->warning()
                ->send();

            return;
        }

        if ($existing) {
            $existing->delete();

            return;
        }

        UnavailableDate::create([
            'product_id' => $productId,
            'branch_id' => $this->branchId,
            'date' => $date,
            'reason' => 'service',
        ]);
    }
}
