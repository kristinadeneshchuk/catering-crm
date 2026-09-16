<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Склад: документи, позиції, інгредієнти, каса — як на проді, плюс справжня
 * міграція чернеток.
 */
trait BuildsStockTestSchema
{
    protected function buildStockSchema(): void
    {
        $make = function (string $table, \Closure $definition): void {
            if (! Schema::hasTable($table)) {
                Schema::create($table, $definition);
            }
        };

        $make('warehouses', fn (Blueprint $t) => [$t->id(), $t->string('name'), $t->timestamps()]);
        $make('suppliers', fn (Blueprint $t) => [$t->id(), $t->string('name'), $t->string('inn')->nullable(),
            $t->string('contact_person')->nullable(), $t->string('phone')->nullable(), $t->timestamps()]);

        // В інших тестових схемах ingredients — мінімальна таблиця під виключення.
        // Для складу потрібні одиниці, ціни й залишок.
        Schema::dropIfExists('ingredients');

        Schema::create('ingredients', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('unit')->default('g');
            $t->decimal('price_per_kg', 10, 2)->default(0);
            $t->integer('yield_percent')->default(100);
            $t->decimal('stock', 12, 3)->default(0);
            $t->string('group')->nullable();
            $t->boolean('is_packaged')->default(false);
            $t->decimal('package_weight', 10, 3)->nullable();
            $t->string('package_unit')->nullable();
            $t->timestamps();
        });

        $make('accounts', function (Blueprint $t) {
            $t->id(); $t->string('name')->nullable(); $t->string('type')->nullable();
            $t->boolean('is_default')->default(false); $t->decimal('balance', 12, 2)->default(0); $t->timestamps();
        });

        $make('transactions', function (Blueprint $t) {
            $t->id();
            $t->string('type');
            $t->string('category')->nullable();
            $t->decimal('amount', 12, 2)->default(0);
            $t->date('date')->nullable();
            $t->text('comment')->nullable();
            $t->unsignedBigInteger('order_id')->nullable();
            $t->unsignedBigInteger('client_id')->nullable();
            $t->unsignedBigInteger('employee_id')->nullable();
            $t->unsignedBigInteger('stock_document_id')->nullable();
            $t->string('method')->nullable();
            $t->unsignedBigInteger('account_id')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->timestamps();
        });

        // Як на проді після всіх міграцій до 15.09.
        $make('stock_documents', function (Blueprint $t) {
            $t->id();
            $t->string('type');
            $t->unsignedBigInteger('warehouse_id');
            $t->unsignedBigInteger('supplier_id')->nullable();
            $t->unsignedBigInteger('account_id')->nullable();
            $t->dateTime('operation_date');
            $t->string('status')->default('completed');
            $t->text('comment')->nullable();
            $t->decimal('total_sum', 10, 2)->default(0);
            $t->boolean('is_paid')->default(false);
            $t->timestamps();
        });

        $make('stock_document_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('stock_document_id');
            $t->morphs('itemable');
            $t->decimal('qty', 10, 3);
            $t->decimal('input_qty', 12, 3)->nullable();
            $t->string('input_unit', 16)->nullable();
            $t->decimal('pack_count', 10, 3)->nullable();
            $t->decimal('pack_price', 12, 4)->nullable();
            $t->decimal('price', 12, 4)->default(0);
            $t->decimal('total_price', 10, 2)->default(0);
            $t->decimal('system_qty', 10, 3)->nullable();
            $t->decimal('difference_qty', 10, 3)->nullable();
            $t->timestamps();
        });

        $make('packagings', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('unit')->default('шт');
            $t->decimal('stock', 12, 3)->default(0);
            $t->decimal('price', 10, 2)->default(0);
            $t->string('project')->nullable();
            $t->string('packaging_type')->nullable();
            $t->timestamps();
        });

        if (! Schema::hasColumn('stock_documents', 'source')) {
            (require database_path('migrations/2026_09_15_120000_add_stock_document_drafts.php'))->up();
        }

        if (! Schema::hasTable('ai_runs')) {
            (require database_path('migrations/2026_09_16_100000_create_ai_runs_table.php'))->up();
        }
    }
}
