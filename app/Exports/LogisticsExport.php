<?php

namespace App\Exports;

use App\Models\Order;
use App\Services\ScheduleService;
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

class LogisticsExport implements FromCollection, WithHeadings, WithMapping, WithStyles, WithColumnWidths
{
    protected $date;

    public function __construct($date)
    {
        $this->date = Carbon::parse($date);
    }

    /**
     * 1. ВИБІРКА ТА "РОЗУМНЕ" ГРУПУВАННЯ
     */
    public function collection()
    {
        $selectedDate = $this->date;
        $nextDay = $selectedDate->copy()->addDay();

        // 1. Отримуємо список замовлень
        $orders = Order::query()
            ->with('client')
            ->where('status', 'active')
            ->get()
            ->filter(function ($order) use ($selectedDate, $nextDay) {
                $isEvening = ScheduleService::isEvening($order->schedule_type);
                
                if (!$isEvening) {
                    return $selectedDate->between(Carbon::parse($order->start_date), Carbon::parse($order->end_date));
                } else {
                    return $nextDay->between(Carbon::parse($order->start_date), Carbon::parse($order->end_date));
                }
            });

        // 2. ГРУПУЄМО З НОРМАЛІЗАЦІЄЮ АДРЕСИ
        $groupedOrders = $orders->groupBy(function($order) {
            // Беремо оригінальну адресу
            $address = mb_strtolower($order->client->address);

            // Список слів, які ми ігноруємо при порівнянні
            $garbageWords = [
                'вулиця', 'вул.', 'вул', 
                'проспект', 'просп.', 'просп', 
                'провулок', 'пров.', 
                'будинок', 'буд.', 'буд', 
                'квартира', 'кв.', 'кв', 
                'місто', 'м.', 'м ', // 'м ' з пробілом, щоб не видалити літеру м з імен
                'під\'їзд', 'під.', 
                'код', 'домофон'
            ];

            // Видаляємо сміттєві слова
            $cleanAddress = str_replace($garbageWords, '', $address);

            // Видаляємо ВСЕ, крім букв і цифр (коми, крапки, дужки, пробіли - геть)
            // Залишаємо тільки a-z, а-я, 0-9
            $cleanAddress = preg_replace('/[^a-zа-яіїєґ0-9]/u', '', $cleanAddress);

            // Тепер адреса виглядає як "шевченка105"
            // Групуємо за: ОчищенаАдреса + Час + Тип
            return $cleanAddress . '_' . $order->delivery_time . '_' . $order->schedule_type;
        });

        // 3. Сортуємо
        return $groupedOrders->sort(function($groupA, $groupB) {
            $orderA = $groupA->first();
            $orderB = $groupB->first();
            $isEveningA = ScheduleService::isEvening($orderA->schedule_type);
            $isEveningB = ScheduleService::isEvening($orderB->schedule_type);
            $keyA = ($isEveningA ? 1 : 0) . '-' . $orderA->delivery_time;
            $keyB = ($isEveningB ? 1 : 0) . '-' . $orderB->delivery_time;
            return strcmp($keyA, $keyB);
        });
    }

    public function headings(): array
    {
        return ['Comp_Id', 'Comp_Name', 'Phone', 'Address', 'Additional_Info', 'TimeWork_Info', 'Unload_Time', 'Qty'];
    }

    public function map($group): array
    {
        $mainOrder = $group->first();
        $quantity = $group->count();

        // Імена через "+"
        $names = $group->map(fn($o) => $o->client->name)->join(' + ');
        // ID через кому
        $ids = $group->map(fn($o) => $o->id)->join(', ');

        $infoParts = [];
        
        // Проекти
        $projects = $group->map(fn($o) => ($o->project === 'u_fit' ? 'U-FIT' : 'Avocado') . " ({$o->calories})")->unique()->join(' | ');
        $infoParts[] = $projects;

        // Коментар доставки (домофон) - беремо найдовший (найповніший) з групи
        $bestDeliveryComment = $group
            ->map(fn($o) => $o->client->delivery_comment)
            ->filter()
            ->sortByDesc(fn($comment) => strlen($comment))
            ->first();

        if ($bestDeliveryComment) {
            $infoParts[] = "Інфо: " . $bestDeliveryComment;
        }

        foreach ($group as $order) {
            if ($order->comment) {
                $infoParts[] = "Комент ({$order->client->name}): " . $order->comment;
            }
            if (!$order->is_paid) {
                $infoParts[] = "!!! БОРГ ({$order->client->name}): " . $order->total_price . " грн";
            }
        }

        $additionalInfoString = implode("; \n", $infoParts);

        return [
            $ids,
            $names,
            $mainOrder->client->phone,
            $mainOrder->client->address, // Залишаємо оригінальну адресу першого клієнта (для читабельності кур'єром)
            $additionalInfoString,
            $mainOrder->delivery_time,
            7,
            $quantity
        ];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 12, 'B' => 30, 'C' => 15, 'D' => 40, 'E' => 50, 'F' => 15, 'G' => 12, 'H' => 8,
        ];
    }

    public function styles(Worksheet $sheet)
    {
        $lastRow = $sheet->getHighestRow();
        $range = 'A1:H' . $lastRow;
        $sheet->getStyle($range)->getAlignment()->setWrapText(true);
        $sheet->getStyle($range)->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
        $sheet->getStyle($range)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        return [1 => ['font' => ['bold' => true, 'size' => 11]]];
    }
}