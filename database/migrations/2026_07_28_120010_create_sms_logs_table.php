<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_logs', function (Blueprint $table) {
            $table->id();

            // Дата ДОСТАВКИ (не дата їжі) + зміна — по них шукаємо, чи вже слали.
            $table->date('date');
            $table->string('shift', 16)->default('all');

            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('order_day_id')->nullable();
            $table->unsignedBigInteger('client_id')->nullable();
            $table->string('client_name')->nullable();

            // Номер клієнта у форматі 380XXXXXXXXX (нормалізований).
            $table->string('phone', 32);

            $table->string('courier_name')->nullable();
            $table->string('courier_phone', 32)->nullable();
            $table->string('car_number', 32)->nullable();

            $table->text('text');

            // sent | failed
            $table->string('status', 16);
            $table->integer('response_code')->nullable();
            $table->string('response_status')->nullable();
            $table->string('message_id')->nullable();
            $table->text('error')->nullable();

            // Сира відповідь TurboSMS — щоб при розборі інциденту було видно, що
            // саме повернув шлюз, а не тільки наш переклад помилки.
            $table->text('response_body')->nullable();

            // Знімок «курʼєр+телефон+авто+маршрут». Якщо маршрути перебудували і
            // fingerprint змінився — клієнту можна слати оновлену SMS повторно.
            $table->string('fingerprint', 64)->nullable();

            $table->unsignedBigInteger('user_id')->nullable();

            $table->timestamps();

            $table->index(['date', 'shift']);
            $table->index(['date', 'phone']);
            $table->index('client_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_logs');
    }
};
