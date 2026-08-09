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
use Illuminate\Support\Facades\DB;
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

        return Product::query()->withoutGlobalScope('published')
            ->with(['brand', 'branches', 'unavailableDates' => fn ($q) => $q
                ->where('branch_id', $this->branchId)
                ->whereBetween('date', [$this->days->first(), $this->days->last()])])
            ->whereHas('branches', fn ($q) => $q->whereKey($this->branchId))
            ->when($this->categoryId, fn ($q) => $q->where('category_id', $this->categoryId))
            ->orderBy('name')
            ->get();
    }

    /**
     * Стан клітинки з урахуванням кількості екземплярів.
     *
     * free — вільні всі · partial — частина в роботі, взяти ще можна ·
     * rented — розібрали повністю · service — стоїть на обслуговуванні.
     *
     * @return array{state: string, free: int, stock: int}
     */
    public function cell(Product $product, string $date): array
    {
        $rows = $product->unavailableDates->filter(
            fn (UnavailableDate $d) => $d->date->toDateString() === $date
        );

        $stock = (int) ($product->branches->firstWhere('id', $this->branchId)?->pivot->qty ?? 0);
        $taken = (int) $rows->sum('qty');
        $free = max(0, $stock - $taken);

        $state = match (true) {
            $taken === 0 => 'free',
            $rows->every(fn (UnavailableDate $d) => $d->reason === 'service') && $free > 0 => 'service',
            $free > 0 => 'partial',
            $rows->contains(fn (UnavailableDate $d) => $d->reason === 'rented') => 'rented',
            default => 'service',
        };

        return ['state' => $state, 'free' => $free, 'stock' => $stock];
    }

    public function toggle(int $productId, string $date): void
    {
        // Знімаємо тільки власне блокування на сервіс; орендовані екземпляри
        // звільняє приймання техніки, а не клік по дошці.
        $service = UnavailableDate::where('product_id', $productId)
            ->where('branch_id', $this->branchId)
            ->whereDate('date', $date)
            ->where('reason', 'service')
            ->first();

        if ($service) {
            $service->delete();

            return;
        }

        $stock = (int) DB::table('inventory')
            ->where('product_id', $productId)
            ->where('branch_id', $this->branchId)
            ->value('qty');

        $taken = (int) UnavailableDate::where('product_id', $productId)
            ->where('branch_id', $this->branchId)
            ->whereDate('date', $date)
            ->sum('qty');

        if ($taken >= $stock) {
            Notification::make()
                ->title('Вільних екземплярів немає')
                ->body('Усі одиниці цього дня вже зайняті орендою — звільнити їх можна тільки прийманням техніки.')
                ->warning()
                ->send();

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
