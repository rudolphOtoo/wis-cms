<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_categories', function (Blueprint $table) {
            $table->string('payment_type')->nullable()->after('display_order');
        });

        // Map existing income categories to payment types for the online
        // giving flow. Only income categories are relevant — expenses
        // are never initiated via online payments.
        $mappings = [
            'Tithe' => 'tithe',
            'Offertory' => 'offering',
            'Welfare' => 'welfare',
            'Scholarship Fund' => 'building_fund',
            'Thanksgiving' => 'special_seed',
            'Others' => 'other',
        ];

        foreach ($mappings as $name => $paymentType) {
            DB::table('finance_categories')
                ->where('name', $name)
                ->where('type', 'income')
                ->update(['payment_type' => $paymentType]);
        }
    }

    public function down(): void
    {
        Schema::table('finance_categories', function (Blueprint $table) {
            $table->dropColumn('payment_type');
        });
    }
};
