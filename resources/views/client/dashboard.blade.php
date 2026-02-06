<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <title>Особистий кабінет</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 text-slate-800">

    <nav class="bg-white shadow-sm border-b border-gray-200">
        <div class="container mx-auto px-4 py-4 flex justify-between items-center">
            <div class="font-bold text-xl text-green-600 flex items-center gap-2">
                🍏 Смачна Доставка
            </div>
            <div class="flex items-center gap-6">
                <span class="text-gray-600">Привіт, <strong>{{ $client->name }}</strong>!</span>
                <form action="{{ route('client.logout') }}" method="POST">
                    @csrf
                    <button class="text-red-500 hover:text-red-700 font-medium text-sm transition">Вийти</button>
                </form>
            </div>
        </div>
    </nav>

    <div class="container mx-auto mt-6 px-4 max-w-4xl">
        @if(session('success'))
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative shadow-sm">
                {{ session('success') }}
            </div>
        @endif
        @if(session('error'))
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative shadow-sm">
                {{ session('error') }}
            </div>
        @endif
    </div>

    <div class="container mx-auto mt-6 p-4 max-w-4xl">
        <div class="flex justify-between items-end mb-6">
            <h1 class="text-3xl font-bold text-gray-800">Мої підписки</h1>
            <button class="bg-green-600 text-white px-5 py-2 rounded-lg shadow hover:bg-green-700 transition font-medium">
                + Нове замовлення
            </button>
        </div>

        @if($orders->isEmpty())
            <div class="bg-white p-10 rounded-xl shadow-sm border border-gray-100 text-center">
                <div class="text-6xl mb-4">🥗</div>
                <h3 class="text-xl font-bold text-gray-700 mb-2">У вас поки немає активних планів</h3>
                <p class="text-gray-500">Саме час почати харчуватися правильно!</p>
            </div>
        @else
            <div class="space-y-4">
                @foreach($orders as $order)
                    <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-200 flex flex-col md:flex-row justify-between items-start md:items-center hover:shadow-md transition">
                        
                        <div>
                            <div class="flex items-center gap-3 mb-1">
                                <h3 class="text-lg font-bold text-gray-800">
                                    {{ $order->tariff->name ?? 'Індивідуальний' }}
                                </h3>
                                <span class="px-2 py-1 rounded text-xs font-bold uppercase
                                    @if($order->status === 'active') bg-green-100 text-green-700
                                    @elseif($order->status === 'new') bg-blue-100 text-blue-700
                                    @elseif($order->status === 'completed') bg-gray-100 text-gray-500
                                    @else bg-yellow-100 text-yellow-700 @endif">
                                    @if($order->status === 'active') Активний
                                    @elseif($order->status === 'new') Новий
                                    @elseif($order->status === 'completed') Завершений
                                    @else {{ $order->status }} @endif
                                </span>
                            </div>
                            
                            <div class="text-gray-500 text-sm space-y-1">
                                <p>📅 {{ \Carbon\Carbon::parse($order->start_date)->format('d.m') }} — {{ \Carbon\Carbon::parse($order->end_date)->format('d.m.Y') }}</p>
                                <p>🔥 {{ $order->calories }} ккал</p>
                            </div>
                        </div>

                        <div class="mt-4 md:mt-0 text-right">
                            <div class="text-2xl font-bold text-gray-900 mb-2">
                                {{ number_format($order->total_price, 0, '.', ' ') }} ₴
                            </div>
                            
                            @if($order->status === 'new')
                                <form action="{{ route('client.order.pay', $order->id) }}" method="POST">
                                    @csrf
                                    <button type="submit" onclick="return confirm('Емуляція оплати: Списати {{ $order->total_price }} грн?')"
                                        class="w-full bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition shadow text-sm font-bold cursor-pointer">
                                        💳 Оплатити
                                    </button>
                                </form>
                            @elseif($order->status === 'active')
                                <div class="text-green-600 text-sm font-medium flex items-center gap-1 justify-end bg-green-50 px-3 py-1 rounded border border-green-200">
                                    ✅ Оплачено / Активний
                                </div>
                            @endif
                        </div>

                    </div>
                @endforeach
            </div>
        @endif
    </div>

</body>
</html>