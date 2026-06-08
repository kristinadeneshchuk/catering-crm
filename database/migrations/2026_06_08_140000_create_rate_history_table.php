<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rate_history', function (Blueprint $table) {
            $table->id();
            $table->string('scope');               // monthly_rent | monthly_utilities | salary:{employee_id}
            $table->decimal('value', 12, 2)->default(0);
            $table->date('effective_from');        // діє з цієї дати
            $table->timestamps();
            $table->index(['scope', 'effective_from']);
        });

        // Сид: поточні оренда/комуналка діють «з давна», щоб усі минулі дні рахувались за ними
        $seedDate = '2000-01-01';
        foreach (['monthly_rent', 'monthly_utilities'] as $key) {
            $val = (float) (DB::table('settings')->where('key', $key)->value('value') ?? 0);
            DB::table('rate_history')->insert([
                'scope'          => $key,
                'value'          => $val,
                'effective_from' => $seedDate,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_history');
    }
};
