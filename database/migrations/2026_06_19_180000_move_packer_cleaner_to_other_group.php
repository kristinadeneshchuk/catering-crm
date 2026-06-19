<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Прибиральниця та пакувальник більше не входять у групу «Кухня» —
        // у дашборді ФОП кухні має показувати тільки кухарів. Решту
        // (паковка, прибирання) виносимо у «Інше», де вона зливається з
        // менеджментом і маркетингом в одну колонку «ФОП — решта».
        DB::table('positions')->whereIn('key', ['packer', 'cleaner'])->update(['group' => 'other']);
    }

    public function down(): void
    {
        DB::table('positions')->whereIn('key', ['packer', 'cleaner'])->update(['group' => 'kitchen']);
    }
};
