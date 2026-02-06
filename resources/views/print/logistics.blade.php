<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <title>Логістика {{ $date->format('d.m.Y') }}</title>
    <style>
        body { font-family: sans-serif; font-size: 13px; margin: 20px; color: #000; }
        h1 { text-align: center; margin-bottom: 20px; font-size: 20px; text-transform: uppercase; }
        
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th, td { border: 1px solid #000; padding: 6px 8px; text-align: left; vertical-align: top; }
        th { background-color: #eee; font-weight: bold; font-size: 12px; }
        
        .col-time { width: 110px; white-space: nowrap; }
        .col-client { width: 20%; }
        .col-phone { width: 15%; white-space: nowrap; }
        .col-address { }
        .col-pay { width: 100px; text-align: center; }

        .badge { display: inline-block; padding: 2px 5px; border-radius: 3px; color: white; font-weight: bold; font-size: 10px; margin-bottom: 3px; }
        .bg-morning { background-color: #16a34a; /* Зелений */ }
        .bg-evening { background-color: #2563eb; /* Синій */ }
        .bg-unpaid { background-color: #dc2626; color: white; font-weight: bold; padding: 2px 4px; border-radius: 3px; }
        
        .comment-box { margin-top: 4px; font-style: italic; color: #333; font-size: 12px; }
        .delivery-note { font-weight: bold; background: #fffbeb; padding: 2px; border: 1px dashed #ca8a04; margin-bottom: 4px; display: block; }
        
        @media print {
            button { display: none !important; }
            body { margin: 0; padding: 10px; }
            @page { margin: 0.5cm; }
        }
    </style>
</head>
<body>
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
        <div>Avocado Food / U-FIT</div>
        <button onclick="window.print()" style="padding: 10px 20px; background: #000; color: #fff; border: none; cursor: pointer; border-radius: 4px;">🖨️ Друкувати список</button>
    </div>

    <h1>🚚 Маршрутний лист — {{ $date->format('d.m.Y') }}</h1>

    <table>
        <thead>
            <tr>
                <th>Час / Тип</th>
                <th>Клієнт</th>
                <th>Телефон</th>
                <th>Адреса доставки та коди</th>
                <th>Статус</th>
            </tr>
        </thead>
        <tbody>
            @foreach($orders as $order)
                @php
                    $isEvening = \App\Services\ScheduleService::isEvening($order->schedule_type);
                @endphp
                <tr>
                    <td class="col-time">
                        @if($isEvening)
                            <span class="badge bg-evening">ВЕЧІР</span>
                        @else
                            <span class="badge bg-morning">РАНОК</span>
                        @endif
                        <div style="font-size: 14px; font-weight: bold; margin-top: 2px;">
                            {{ $order->delivery_time }}
                        </div>
                        
                        {{-- === ДОДАНО ID ЗАМОВЛЕННЯ === --}}
                        <div style="font-size: 11px; color: #555; margin-top: 4px;">
                            ID: <strong>{{ $order->id }}</strong>
                        </div>
                    </td>
                    <td class="col-client">
                        <div style="font-weight: bold; font-size: 14px;">{{ $order->client->name }}</div>
                        <div style="color: #666; font-size: 11px;">
                            {{ $order->project === 'u_fit' ? 'U-FIT' : 'Avocado' }} | {{ $order->calories }} ккал
                        </div>
                    </td>
                    <td class="col-phone">
                        {{ $order->client->phone }}
                    </td>
                    <td class="col-address">
                        <div style="font-size: 13px; font-weight: bold; margin-bottom: 5px;">
                            {{ $order->client->address }}
                        </div>

                        {{-- 1. Коментар ДОСТАВКИ (з картки клієнта) --}}
                        @if($order->client->delivery_comment)
                            <span class="delivery-note">
                                🔑 {{ $order->client->delivery_comment }}
                            </span>
                        @endif

                        {{-- 2. Коментар ЗАМОВЛЕННЯ --}}
                        @if($order->comment)
                            <div class="comment-box">
                                ℹ️ {{ $order->comment }}
                            </div>
                        @endif
                    </td>
                    <td class="col-pay">
                        @if(!$order->is_paid)
                            <div style="margin-bottom: 4px;">
                                <span class="bg-unpaid">НЕ ОПЛ.</span>
                            </div>
                            <div style="font-weight: bold;">{{ number_format($order->total_price, 0, '', ' ') }} ₴</div>
                        @else
                            <span style="color: green; font-weight: bold;">✓ Ок</span>
                        @endif
                    </td>
                </tr>
            @endforeach

            @if($orders->isEmpty())
                <tr>
                    <td colspan="5" style="text-align: center; padding: 20px;">
                        Немає активних доставок на цю дату.
                    </td>
                </tr>
            @endif
        </tbody>
    </table>
</body>
</html>