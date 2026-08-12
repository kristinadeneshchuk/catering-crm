<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Рахунки на оплату.
     *
     * Реквізити зберігаємо знімком (requisites), а не посиланням на проєкт:
     * рахунок — документ, який клієнт уже отримав, і він не має змінюватись
     * заднім числом, якщо у бренду поміняється ФОП чи банк.
     *
     * token — для публічного посилання на PDF. Так само зроблено з меню
     * замовлення (orders.menu_token) і кабінетом клієнта.
     */
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();

            // Номер у форматі «порядковий/замовлення», напр. 517/1499.
            $table->string('number', 32)->unique();
            $table->unsignedInteger('sequence');

            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('client_id')->nullable();
            $table->string('project')->nullable();

            $table->date('issued_on');
            $table->decimal('amount', 10, 2);
            $table->string('purpose', 500)->nullable();

            $table->json('requisites')->nullable();

            $table->string('token', 64)->unique();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();

            $table->index('order_id');
            $table->index('client_id');
            $table->index(['project', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
