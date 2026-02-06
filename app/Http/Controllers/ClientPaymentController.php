<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\Request;

class ClientPaymentController extends Controller
{
    // Метод оплаты заказа
    public function pay(Order $order)
    {
        // 1. Проверка безопасности: Это заказ текущего клиента?
        if ($order->client_id !== auth()->guard('client')->id()) {
            abort(403, 'Это не ваш заказ!');
        }

        // 2. Проверка: Можно ли оплатить?
        if ($order->status !== 'new') {
            return back()->with('error', 'Этот заказ уже оплачен или закрыт.');
        }

        // --- ЗДЕСЬ БУДЕТ STRIPE/LIQPAY ---
        // Тут мы обычно отправляем запрос в банк: "Спиши 2000 грн".
        // Банк отвечает: "Успешно".
        // Мы пока просто эмулируем успех.
        // ----------------------------------

        // 3. Активируем заказ
        $order->update([
            'status' => 'active',
            // 'transaction_id' => 'tx_123456789', // Сюда потом запишем номер чека из банка
        ]);

        // 4. Возвращаем клиента назад с поздравлением
        return back()->with('success', 'Ура! Оплата прошла успешно. Заказ активирован! 🍏');
    }
}