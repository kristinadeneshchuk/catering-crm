<?php

namespace App\Exports;

use App\Models\Order;
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

class LogisticsExport implements FromCollection, WithHeadings, WithStyles, WithColumnWidths
{
    protected $targetDate;
    protected $shift; // 'morning' або 'evening'

    public function __construct($date, $shift = 'morning')
    {
        // Додаємо 1 день, щоб логістика завжди брала меню на ЗАВТРА
        $this->targetDate = Carbon::parse($date)->addDay()->format('Y-m-d');
        $this->shift = $shift;
    }

    public function collection()
    {
        $targetDate = $this->targetDate;
        $shift = $this->shift;

        // 1. Шукаємо замовлення, які мають запис у календарі на цей день
        $orders = Order::query()
            ->with(['client', 'orderDays'])
            ->whereIn('status', ['active', 'new'])
            // 🔥 ФІЛЬТР ПО ЗМІНІ (Ранок або Вечір)
            ->where(function ($query) use ($shift) {
                if ($shift === 'morning') {
                    // Шукаємо графіки, що містять слово ранок або morning
                    $query->where('schedule_type', 'like', '%morning%')
                          ->orWhere('schedule_type', 'like', '%ранок%');
                } else {
                    // Шукаємо графіки, що містять слово вечір або evening
                    $query->where('schedule_type', 'like', '%evening%')
                          ->orWhere('schedule_type', 'like', '%вечір%');
                }
            })
            ->whereHas('orderDays', function ($query) use ($targetDate) {
                $query->where('date', $targetDate);
            })
            ->get();

        // 2. Групуємо за "чистою" адресою
        $groupedOrders = $orders->groupBy(function($order) {
            $defaultAddr = $order->client->addresses()->where('is_default', true)->first();
            $address = mb_strtolower($defaultAddr?->address ?? $order->client->address ?? '');
            
            $garbageWords = ['вулиця', 'вул.', 'вул', 'проспект', 'просп.', 'просп', 'провулок', 'пров.', 'будинок', 'буд.', 'буд', 'квартира', 'кв.', 'кв', 'місто', 'м.', 'під\'їзд', 'під.', 'код', 'домофон'];
            $cleanAddress = str_replace($garbageWords, '', $address);
            $cleanAddress = preg_replace('/[^a-zа-яіїєґ0-9]/u', '', $cleanAddress);

            // Групуємо: Адреса + Час
            return $cleanAddress . '_' . ($order->delivery_time ?? 'no_time');
        });

        // 3. Формуємо рядки для Excel
        $rows = $groupedOrders->map(function ($group) {
            $mainOrder = $group->first();
            $client = $mainOrder->client;
            
            // Імена всіх клієнтів у групі
            $names = $group->map(fn($o) => $o->client->name)->unique()->join(' + ');
            
            // ID замовлень або клієнтів
            $ids = $group->map(fn($o) => $o->client->id)->unique()->join(', ');

            // Інформація
            $infoParts = [];
            
            // Проекти і калорії
            $projects = $group->map(fn($o) => 
                ($o->projectData?->name ?? $o->project) . " (" . (int)$o->calories . ")"
            )->join(' | ');
            $infoParts[] = $projects;

            // Найдовший коментар по доставці (щоб не втратити код домофону)
            $bestDeliveryComment = $group
                ->map(fn($o) => $o->client->addresses()->where('is_default', true)->first()?->delivery_comment ?? $o->client->delivery_comment)
                ->filter()
                ->sortByDesc(fn($s) => mb_strlen($s))
                ->first();
            
            if ($bestDeliveryComment) {
                $infoParts[] = "Інфо: " . $bestDeliveryComment;
            }

            // Коментарі до замовлення
            foreach ($group as $o) {
                if (!empty($o->comment)) {
                    $infoParts[] = "Комент ({$o->client->name}): {$o->comment}";
                }
            }
            
            $additionalInfo = implode("\n", $infoParts);

            return [
                'Comp_Id' => $ids,
                'Comp_Name' => $names,
                'Phone' => $client->phone,
                'Address' => (function() use ($client) {
                    $addr = $client->addresses()->where('is_default', true)->first();
                    if (!$addr) return $client->address;
                    $parts = array_filter([
                        $addr->address,
                        $addr->address_entrance ? 'під\'їзд ' . $addr->address_entrance : null,
                        $addr->address_floor ? 'пов' . $addr->address_floor : null,
                        $addr->address_apartment ? 'кв ' . $addr->address_apartment : null,
                    ]);
                    return implode(', ', $parts);
                })(),
                'Note' => $additionalInfo,
                'TimeWork_Info' => $mainOrder->delivery_time, // Час доставки
                'Unload_Time' => 7,
                'Qty' => $group->count()
            ];
        });

        // 4. Сортуємо по часу доставки
        return $rows->sortBy('TimeWork_Info')->values();
    }

    public function headings(): array
    {
        return ['Comp_Id', 'Comp_Name', 'Phone', 'Address', 'Note', 'TimeWork_Info', 'Unload_Time', 'Qty'];
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
        if ($lastRow < 2) return [];

        $range = 'A1:H' . $lastRow;
        
        $sheet->getStyle($range)->getAlignment()->setWrapText(true);
        $sheet->getStyle($range)->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
        $sheet->getStyle($range)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        
        // Колір шапки залежно від зміни (Ранок - синій, Вечір - помаранчевий)
        $headerColor = $this->shift === 'morning' ? '4472C4' : 'ED7D31';

        return [
            1 => ['font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => 'FFFFFF']], 'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => $headerColor]]]
        ];
    }
}