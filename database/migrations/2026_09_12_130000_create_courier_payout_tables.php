<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Виплата курʼєру з погодженням у Telegram.
     *
     * Досі все вводив менеджер руками — пробіг, готівку, — і нарахування одразу
     * рухало employees.balance. Логіку нарахування не чіпаємо: додаємо шар
     * звіту від курʼєра і погодження власником поверх неї.
     */
    public function up(): void
    {
        // Звʼязок співробітника з ботом. Без нього боту нікуди надіслати шаблон
        // звіту — а на цьому стоїть увесь етап. Курʼєр підключається сам:
        // відкриває посилання з одноразовим кодом, бот запамʼятовує його чат.
        Schema::table('employees', function (Blueprint $table) {
            $table->string('telegram_chat_id', 32)->nullable()->after('phone');
            $table->string('telegram_link_code', 32)->nullable()->unique()->after('telegram_chat_id');
        });

        // Спосіб оплати замовлення. Поля не було ніде — ні в замовленні, ні в
        // клієнті, — тож «рядки готівки для курʼєра» не було з чого брати.
        // null — невідомо (усі старі замовлення), cash — готівка курʼєру,
        // transfer — переказ.
        if (! Schema::hasColumn('orders', 'payment_method')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('payment_method', 16)->nullable()->after('is_paid');
            });
        }

        // Звіт зміни: бот надсилає заповнений шаблон, курʼєр дописує порожнє і
        // шле назад одним повідомленням із фото. Тут живе стан між цими кроками.
        Schema::create('courier_shift_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->date('date');
            $table->string('shift_slot', 16); // morning | evening
            $table->unsignedBigInteger('delivery_route_id')->nullable();

            $table->string('tg_chat_id', 32)->nullable();
            $table->string('template_message_id', 32)->nullable();
            $table->timestamp('template_sent_at')->nullable();
            $table->timestamp('reminded_at')->nullable();

            // Що саме очікували: пробіг на старті, ціна пального і рядки готівки.
            $table->json('expected')->nullable();

            // Що прислав курʼєр.
            $table->text('raw_text')->nullable();
            $table->json('parsed')->nullable();
            $table->json('photos')->nullable(); // шляхи в приватному сховищі
            $table->json('problems')->nullable();

            // sent → incomplete → accepted
            $table->string('status', 16)->default('sent');
            $table->timestamp('accepted_at')->nullable();

            $table->timestamps();

            $table->unique(['employee_id', 'date', 'shift_slot']);
            $table->index(['status', 'date']);
        });

        Schema::create('courier_payouts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');
            $table->date('date');
            $table->string('shift_slot', 16)->nullable();
            $table->unsignedBigInteger('delivery_route_id')->nullable();
            $table->unsignedBigInteger('mileage_log_id')->nullable();

            // Знімок розрахунку: ставка, точки, дальні, пробіг, бонуси, штрафи.
            // Знімок, а не посилання: після погодження цифри не мають пливти від
            // того, що хтось пізніше поправив маршрут.
            $table->json('components');
            $table->decimal('total', 10, 2);
            $table->decimal('cash_on_hand', 10, 2)->default(0);
            $table->decimal('to_pay', 10, 2);

            // draft → sent → approved / rejected → paid
            $table->string('status', 16)->default('draft');

            // Погоджують із Telegram, тож знаємо чат, а не користувача CRM.
            $table->string('approved_by_tg', 32)->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();

            // Повідомлення йде двом людям — власнику і старшому менеджеру, і
            // після правки треба оновити обидва. Тому список, а не одна пара.
            $table->json('tg_messages')->nullable();

            $table->string('reject_reason', 500)->nullable();
            $table->text('comment')->nullable();

            $table->timestamps();

            $table->unique(['employee_id', 'date']);
            $table->index(['status', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_payouts');
        Schema::dropIfExists('courier_shift_reports');

        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn('payment_method'));

        Schema::table('employees', function (Blueprint $table) {
            $table->dropUnique(['telegram_link_code']);
            $table->dropColumn(['telegram_chat_id', 'telegram_link_code']);
        });
    }
};
