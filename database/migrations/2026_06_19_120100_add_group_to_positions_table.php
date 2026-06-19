<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->string('group', 32)->default('other')->after('payment_type');
        });

        // Бекфіл груп для існуючих посад
        $defaults = [
            'cook'       => 'kitchen',
            'packer'     => 'kitchen',
            'cleaner'    => 'kitchen',
            'courier'    => 'couriers',
            'manager'    => 'management',
            'admin'      => 'management',
            'buxgalter'  => 'management',
            'targetolog' => 'marketing',
            'smm'        => 'marketing',
            'dizainer'   => 'marketing',
        ];

        foreach ($defaults as $key => $group) {
            DB::table('positions')->where('key', $key)->update(['group' => $group]);
        }
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->dropColumn('group');
        });
    }
};
