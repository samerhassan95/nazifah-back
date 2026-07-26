<?php

namespace Modules\Payment\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Payment\Models\PaymentMethod;

class PaymentMethodSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
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
            PaymentMethod::updateOrCreate(
                ['method_key' => $method['method_key']],
                $method
            );
        }
    }
}
