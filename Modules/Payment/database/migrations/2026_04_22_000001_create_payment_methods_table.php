<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('method_key')->unique(); // e.g., 'cash_on_delivery', 'visa', 'mada'
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Seed initial payment methods
        $paymentMethods = [
            ['method_key' => 'cash_on_delivery', 'is_active' => true],
            ['method_key' => 'visa', 'is_active' => true],
            ['method_key' => 'mastercard', 'is_active' => true],
            ['method_key' => 'mada', 'is_active' => true],
            ['method_key' => 'nazefah_wallet', 'is_active' => true],
            ['method_key' => 'stc_pay', 'is_active' => true],
            ['method_key' => 'apple_pay', 'is_active' => true],
            ['method_key' => 'google_pay', 'is_active' => true],
            ['method_key' => 'samsung_pay', 'is_active' => true],
        ];

        foreach ($paymentMethods as $method) {
            DB::table('payment_methods')->insert(array_merge($method, [
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};
