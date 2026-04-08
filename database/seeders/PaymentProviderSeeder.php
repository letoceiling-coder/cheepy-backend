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
            ['name' => 'tinkoff', 'is_active' => true, 'config' => [
                'currency' => 'rub',
                'terminal_key' => '',
                'password' => '',
                'notification_url' => '',
                'success_url' => '',
                'fail_url' => '',
            ]],
            ['name' => 'sber', 'is_active' => true, 'config' => [
                'currency' => 'rub',
                'merchant_login' => '',
                'password' => '',
                'success_url' => '',
                'fail_url' => '',
            ]],
            ['name' => 'atol', 'is_active' => true, 'config' => [
                'currency' => 'rub',
                'login' => '',
                'password' => '',
                'group_code' => '',
                'tax' => 'none',
            ]],
        ];

        foreach ($providers as $p) {
            PaymentProvider::updateOrCreate(
                ['name' => $p['name']],
                ['is_active' => $p['is_active'], 'config' => $p['config']]
            );
        }
    }
}
