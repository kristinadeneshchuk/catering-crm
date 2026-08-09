<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Product;
use App\Models\UnavailableDate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Залишки і зайняті дати.
 *
 * Дати рахуються від «сьогодні», а не прибиті до серпня 2026, — інакше демо
 * протухає за тиждень і календар показує суцільне минуле.
 */
class AvailabilitySeeder extends Seeder
{
    public function run(): void
    {
        $today = Carbon::today();
        $branches = Branch::with('city')->get();
        $products = Product::all();

        foreach ($products as $product) {
            foreach ($branches as $branch) {
                // Харків і Львів тримають не весь парк — важкої техніки там нема.
                $heavy = $product->weight_kg >= 60;
                if ($branch->city->slug !== 'kyiv' && $heavy) {
                    continue;
                }

                // У Києві ходові позиції тримають у кількох екземплярах —
                // на цьому й перевіряється, що наявність рахується поштучно.
                $branch->products()->attach($product->id, [
                    'qty' => match (true) {
                        $branch->city->slug !== 'kyiv' => 1,
                        $heavy => 1,
                        $product->popularity >= 80 => 3,
                        default => 2,
                    },
                ]);
            }
        }

        /*
         | Зайнятість. Детермінована формула замість random(): сид має бути
         | відтворюваним, інакше тести на конфлікт дат «мигають».
         */
        foreach ($products as $product) {
            foreach ($product->branches as $branch) {
                $offsets = $this->busyOffsets($product->id, $branch->id);

                foreach ($offsets as $offset) {
                    UnavailableDate::create([
                        'product_id' => $product->id,
                        'branch_id' => $branch->id,
                        'date' => $today->copy()->addDays($offset),
                        'reason' => $offset % 7 === 0 ? 'service' : 'rented',
                    ]);
                }
            }
        }
    }

    /** @return list<int> зміщення зайнятих днів від сьогодні */
    private function busyOffsets(int $productId, int $branchId): array
    {
        $seed = ($productId * 7 + $branchId * 13) % 11;

        // Кожна модель у кожній філії має свій «хвіст» оренди: два блоки по 2 дні.
        return [
            $seed + 5,
            $seed + 6,
            $seed + 12,
            $seed + 13,
        ];
    }
}
