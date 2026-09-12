<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Повідомлення про оплату — а не сама оплата.
     *
     * Досі гроші в CRM зʼявлялись одразу транзакцією, і прапорець is_paid
     * ставився автоматично. Коли в розмову вступає ШІ-агент продажів, так уже не
     * можна: клієнт пише «оплатив» або «передав готівкою курʼєру», і агент мусить
     * це зафіксувати, але не має права вирішувати, що гроші справді прийшли.
     *
     * Тому заява живе окремо і НІКОЛИ не змінює is_paid. Гроші зʼявляються лише
     * тоді, коли менеджер її підтвердить: тоді створюється звичайна транзакція, і
     * далі працює чинний ланцюжок — перерахунок is_paid і вебхук в Inbox.
     */
    public function up(): void
    {
        Schema::create('payment_claims', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('client_id');

            // client_transfer — клієнт пише «оплатив» (скрін);
            // client_cash     — клієнт пише «передав готівкою курʼєру»;
            // courier_cash    — курʼєр вказав готівку у звіті зміни;
            // invoice_sent    — виставлено рахунок, чекаємо оплату.
            $table->string('source', 24);

            $table->decimal('amount', 10, 2);
            $table->string('method', 16); // cash | transfer

            // Хто повідомив: агент продажів, курʼєр (employee) чи менеджер (user).
            $table->string('reported_by_type', 16);
            $table->unsignedBigInteger('reported_by_id')->nullable();

            // Для готівки: хто взяв гроші і на якій доставці.
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->unsignedBigInteger('order_day_id')->nullable();
            $table->unsignedBigInteger('route_stop_id')->nullable();

            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->string('attachment_path')->nullable();
            $table->text('comment')->nullable();

            // pending | confirmed | rejected | superseded
            $table->string('status', 16)->default('pending');

            // Пара «клієнт сказав — курʼєр підтвердив» про ту саму готівку.
            // Зводимо в пару, щоб менеджер бачив одну заяву, а не дві, і щоб
            // розбіжність сум («9400 проти 9300») світилась одразу.
            $table->unsignedBigInteger('paired_claim_id')->nullable();

            $table->unsignedBigInteger('confirmed_by')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->string('reject_reason', 500)->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('order_id');
            $table->index('client_id');
            $table->index(['employee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_claims');
    }
};
