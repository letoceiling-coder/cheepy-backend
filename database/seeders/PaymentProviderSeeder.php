<?php

namespace Database\Seeders;

use App\Models\PaymentProvider;
use Illuminate\Database\Seeder;

class PaymentProviderSeeder extends Seeder
{
    public function run(): void
    {
        $providers = [
            ['name' => 'stripe', 'is_active' => true, 'config' => ['currency' => 'usd']],
            ['name' => 'tinkoff', 'is_active' => true, 'config' => ['currency' => 'rub']],
            ['name' => 'sber', 'is_active' => true, 'config' => ['currency' => 'rub']],
            ['name' => 'atol', 'is_active' => true, 'config' => ['currency' => 'rub']],
        ];

        foreach ($providers as $p) {
            PaymentProvider::updateOrCreate(
                ['name' => $p['name']],
                ['is_active' => $p['is_active'], 'config' => $p['config']]
            );
        }
    }
}
