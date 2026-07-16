<?php

namespace App\Exports;

use App\Models\OrderDay;
use App\Services\ScheduleService;
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
    /** Дата ДОСТАВКИ (фізично везуть). Для ранку = inputDate+1, для вечора = inputDate. */
    protected string $deliveryDate;
    protected string $shift; // 'morning' або 'evening'

    public function __construct($date, $shift = 'morning')
    {
        // Логіка UI: користувач задає "сьогодні" (дата фасування).
        // Ранкова зміна везе на завтра; вечірня — сьогодні ввечері.
        $this->deliveryDate = $shift === 'morning'
            ? Carbon::parse($date)->addDay()->format('Y-m-d')
            : Carbon::parse($date)->format('Y-m-d');

        $this->shift = $shift;
    }

    public function collection()
    {
        $shift        = $this->shift;
        $deliveryDate = Carbon::parse($this->deliveryDate)->startOfDay();

        // 1. Тягнемо всі OrderDay у вікні [-1..+4] днів від доставки і фільтруємо за resolveDeliveryDate.
        $foodFrom = $deliveryDate->copy()->subDay()->format('Y-m-d');
        $foodTo   = $deliveryDate->copy()->addDays(4)->format('Y-m-d');

        $query = OrderDay::query()
            ->with(['order.client.addresses', 'order.projectData'])
            ->whereBetween('date', [$foodFrom, $foodTo])
            ->whereHas('order', fn ($q) => $q->whereIn('status', ['active', 'new']));

        // Фільтр shift + точна відповідність даті — обидва у PHP, бо shift залежить
        // від override delivery_time на orderDay, який не завжди відповідає schedule_type.
        // Той самий алгоритм ефективного часу — у PrintController::miniManifest і в
        // AntLogisticsService::collectOrderDaysForDelivery, щоб наклейки, мураха і Excel
        // рахували ранок/вечір однаково.
        $days = $query->get()->filter(function (OrderDay $day) use ($deliveryDate, $shift) {
            if (!$day->resolveDeliveryDate()->isSameDay($deliveryDate)) {
                return false;
            }

            $overrideTime = $day->delivery_time;
            if ($overrideTime) {
                $hour = (int) explode(':', $overrideTime)[0];
                $isEvening = $hour >= 12;
            } else {
                $isEvening = ScheduleService::isEvening($day->order?->schedule_type);
            }

            return $shift === 'evening' ? $isEvening : !$isEvening;
        });

        if ($days->isEmpty()) {
            return collect();
        }

        // 2. Групуємо за "чистою" адресою + часом доставки
        $grouped = $days->groupBy(function (OrderDay $day) {
            $order       = $day->order;
            $client      = $order?->client;
            $defaultAddr = $client?->addresses->firstWhere('is_default', true);
            $address     = mb_strtolower($day->address ?? $defaultAddr?->address ?? $client?->address ?? '');

            $garbageWords = ['вулиця', 'вул.', 'вул', 'проспект', 'просп.', 'просп', 'провулок', 'пров.', 'будинок', 'буд.', 'буд', 'квартира', 'кв.', 'кв', 'місто', 'м.', 'під\'їзд', 'під.', 'код', 'домофон'];
            $cleanAddress = str_replace($garbageWords, '', $address);
            $cleanAddress = preg_replace('/[^a-zа-яіїєґ0-9]/u', '', $cleanAddress);

            $deliveryTime = $day->delivery_time ?? $order?->delivery_time ?? 'no_time';

            return $cleanAddress . '_' . $deliveryTime;
        });

        // 3. Формуємо рядки для Excel
        $rows = $grouped->map(function ($group) {
            /** @var OrderDay $mainDay */
            $mainDay   = $group->first();
            $mainOrder = $mainDay->order;
            $client    = $mainOrder?->client;

            // Унікальні замовлення (Order) у групі — для імен/проєктів/коментарів
            $uniqueOrders = $group->map(fn (OrderDay $d) => $d->order)->filter()->unique('id');

            $names = $uniqueOrders->map(fn ($o) => $o->client?->name)->filter()->unique()->join(' + ');
            $ids   = (string) ($mainOrder?->client_id ?? '');

            $infoParts = [];

            $projects = $uniqueOrders->map(fn ($o) =>
                ($o->projectData?->name ?? $o->project) . ' (' . (int) $o->calories . ')'
            )->join(' | ');
            if ($projects) {
                $infoParts[] = $projects;
            }

            // Подвійна доставка → перерахуємо дати їжі
            $datesNote = $this->buildFoodDatesNote($group);
            if ($datesNote) {
                $infoParts[] = $datesNote;
            }

            $bestDeliveryComment = $group
                ->map(function (OrderDay $d) {
                    $client      = $d->order?->client;
                    $dayComment  = $d->delivery_comment;
                    $defaultAddr = $client?->addresses->firstWhere('is_default', true)
                        ?? $client?->addresses->first();
                    $addrComment = $defaultAddr?->delivery_comment ?? $client?->delivery_comment;
                    $parts = array_filter(array_unique([$dayComment, $addrComment]));
                    return implode(' / ', $parts) ?: null;
                })
                ->filter()
                ->sortByDesc(fn ($s) => mb_strlen($s))
                ->first();

            if ($bestDeliveryComment) {
                $infoParts[] = 'Інфо: ' . $bestDeliveryComment;
            }

            foreach ($uniqueOrders as $o) {
                if (!empty($o->comment)) {
                    $infoParts[] = 'Комент (' . ($o->client?->name ?? '') . '): ' . $o->comment;
                }
            }

            $additionalInfo = implode("\n", $infoParts);

            return [
                'Comp_Id'         => $ids,
                'Comp_Name'       => $names,
                'Phone'           => $client?->phone,
                'Address'         => $this->formatAddress($mainDay, $client),
                'Additional_Info' => $additionalInfo,
                'TimeWork_Info'   => $mainDay->delivery_time ?? $mainOrder?->delivery_time,
                'Unload_Time'     => 7,
                'Qty'             => $group->count(),
            ];
        });

        return $rows->sortBy('TimeWork_Info')->values();
    }

    private function formatAddress(OrderDay $day, ?\App\Models\Client $client): string
    {
        if ($day->address) {
            $parts = array_filter([
                $day->address,
                $day->address_entrance ? 'під\'їзд ' . $day->address_entrance : null,
                $day->address_floor ? 'пов' . $day->address_floor : null,
                $day->address_apartment ? 'кв ' . $day->address_apartment : null,
            ]);
            return implode(', ', $parts);
        }

        if (!$client) return '';

        $addr = $client->addresses->firstWhere('is_default', true)
            ?? $client->addresses->first();
        if (!$addr) return (string) $client->address;

        $parts = array_filter([
            $addr->address,
            $addr->address_entrance ? 'під\'їзд ' . $addr->address_entrance : null,
            $addr->address_floor ? 'пов' . $addr->address_floor : null,
            $addr->address_apartment ? 'кв ' . $addr->address_apartment : null,
        ]);
        return implode(', ', $parts);
    }

    private function buildFoodDatesNote(\Illuminate\Support\Collection $group): string
    {
        $dates = $group->pluck('date')->map(fn ($d) => Carbon::parse($d))
            ->unique(fn (Carbon $d) => $d->format('Y-m-d'))->sort();
        if ($dates->count() < 2) return '';

        $map = ['Mon' => 'пн', 'Tue' => 'вт', 'Wed' => 'ср', 'Thu' => 'чт', 'Fri' => 'пт', 'Sat' => 'сб', 'Sun' => 'нд'];
        $parts = $dates->map(fn (Carbon $d) => ($map[$d->format('D')] ?? '') . ' ' . $d->format('d.m'))->all();

        return 'Раціон: ' . implode(' + ', $parts);
    }

    public function headings(): array
    {
        return ['Comp_Id', 'Comp_Name', 'Phone', 'Address', 'Additional_Info', 'TimeWork_Info', 'Unload_Time', 'Qty'];
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