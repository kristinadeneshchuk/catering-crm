<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Відмітки «+» у чаті кухні (docs/tz-ops-agent.md §2): звідки зміна,
     * на якій позиції людина працювала цього дня і що зауважив ШІ.
     */
    public function up(): void
    {
        Schema::table('employee_shifts', function (Blueprint $table) {
            $table->string('source', 16)->nullable()->after('is_planned'); // kitchen_chat | manual
            $table->string('position_key', 32)->nullable()->after('source');
            $table->text('ai_comment')->nullable();
        });

        // Позиції кухні, яких бракувало: шеф-кухар і помічник.
        foreach ([
            ['key' => 'chef', 'name' => 'Шеф-кухар', 'sort_order' => 0],
            ['key' => 'assistant', 'name' => 'Помічник кухаря', 'sort_order' => 2],
        ] as $position) {
            if (DB::table('positions')->where('key', $position['key'])->exists()) {
                continue;
            }

            $row = $position + [
                'color'        => 'warning',
                'payment_type' => 'per_shift',
                'group'        => 'kitchen',
                'is_active'    => 1,
                'created_at'   => now(),
                'updated_at'   => now(),
            ];

            // Колонки довідника відрізняються між середовищами — пишемо лише наявні.
            DB::table('positions')->insert(array_filter(
                $row,
                fn (string $column) => Schema::hasColumn('positions', $column),
                ARRAY_FILTER_USE_KEY,
            ));
        }
    }

    public function down(): void
    {
        Schema::table('employee_shifts', fn (Blueprint $t) => $t->dropColumn(['source', 'position_key', 'ai_comment']));
    }
};
