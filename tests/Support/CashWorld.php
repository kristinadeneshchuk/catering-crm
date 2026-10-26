<?php

namespace Tests\Support;

use App\Models\Account;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;

/** Готівка 5000 ₴, картка 10 000 ₴, склад і співробітники — для тестів «Каси». setUpCashWorld() Laravel викликає сам. */
trait CashWorld
{
    use BuildsStockTestSchema;

    protected Account $cash;
    protected Account $card;

    protected function setUpCashWorld(): void
    {
        Bus::fake();
        $this->buildStockSchema();

        foreach (['users', 'employees', 'positions', 'rate_history'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('email')->unique(); $t->string('password');
            $t->string('role')->default('manager'); $t->rememberToken(); $t->timestamps();
        });
        Schema::create('employees', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->decimal('balance', 12, 2)->default(0); $t->timestamps();
        });

        Schema::create('positions', function (Blueprint $t) {
            $t->id(); $t->string('key'); $t->string('payment_type')->nullable(); $t->timestamps();
        });
        Schema::create('rate_history', function (Blueprint $t) {
            $t->id(); $t->string('scope'); $t->decimal('value', 12, 2); $t->date('effective_from'); $t->timestamps();
        });

        $this->card = Account::create(['name' => 'ФОП картка', 'type' => 'card', 'balance' => 10000, 'is_default' => true]);
        $this->cash = Account::create(['name' => 'Готівка', 'type' => 'cash', 'balance' => 5000]);
    }

    protected function login(string $role = 'admin'): User
    {
        $user = User::create(['name' => $role, 'email' => $role.'@t.test', 'password' => 'x', 'role' => $role]);
        $this->actingAs($user);

        return $user;
    }
}
