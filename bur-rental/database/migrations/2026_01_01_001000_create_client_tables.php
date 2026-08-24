<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Кабінет клієнта.
 *
 * Клієнти живуть окремо від `users` навмисно: `users` — це співробітники з
 * доступом до адмінки, і жодна помилка в ролях не повинна відкрити клієнту
 * Filament. Різні таблиці, різні guard'и — і питання закрите.
 *
 * Ідентифікатор клієнта — телефон, бо саме він обов'язковий у брони. Пошта
 * необов'язкова: половина замовлень на такому сайті приходить без неї.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('phone')->unique();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('company')->nullable();
            $table->string('edrpou')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        // Одноразові коди входу. Зберігається хеш, а не сам код: база з
        // живими кодами — це база з ключами від чужих кабінетів.
        Schema::create('client_login_codes', function (Blueprint $table) {
            $table->id();
            $table->string('phone')->index();
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();
        });

        Schema::create('favourites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['client_id', 'product_id']);
        });

        Schema::table('bookings', function (Blueprint $table) {
            // Бронь створюється й без кабінету, тому зв'язок необов'язковий:
            // клієнт може зайти пізніше, і броні підтягнуться за телефоном.
            $table->foreignId('client_id')->nullable()->after('id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_id');
        });

        Schema::dropIfExists('favourites');
        Schema::dropIfExists('client_login_codes');
        Schema::dropIfExists('clients');
    }
};
