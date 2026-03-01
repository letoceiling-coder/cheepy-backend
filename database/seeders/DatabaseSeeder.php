<?php

namespace Database\Seeders;

use App\Models\AdminUser;
use App\Models\Setting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Admin user
        AdminUser::updateOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@sadavod.loc')],
            [
                'name' => 'Administrator',
                'password' => Hash::make(env('ADMIN_PASSWORD', 'admin123')),
                'role' => 'admin',
                'is_active' => true,
            ]
        );

        // Default settings
        $defaults = [
            ['group' => 'general',   'key' => 'site_name',              'value' => 'Садовод База',     'type' => 'string', 'label' => 'Название сайта'],
            ['group' => 'general',   'key' => 'donor_url',              'value' => 'https://sadovodbaza.ru', 'type' => 'string', 'label' => 'URL донора'],
            ['group' => 'general',   'key' => 'items_per_page',         'value' => '24',               'type' => 'int',    'label' => 'Товаров на странице'],

            ['group' => 'parser',    'key' => 'request_delay_ms',       'value' => '500',              'type' => 'int',    'label' => 'Задержка между запросами (мс)'],
            ['group' => 'parser',    'key' => 'default_products_limit', 'value' => '0',                'type' => 'int',    'label' => 'Лимит товаров (0=все)'],
            ['group' => 'parser',    'key' => 'default_max_pages',      'value' => '0',                'type' => 'int',    'label' => 'Макс. страниц (0=все)'],
            ['group' => 'parser',    'key' => 'save_photos_default',    'value' => '0',                'type' => 'bool',   'label' => 'Скачивать фото по умолчанию'],
            ['group' => 'parser',    'key' => 'save_to_db',             'value' => '1',                'type' => 'bool',   'label' => 'Сохранять в БД'],
            ['group' => 'parser',    'key' => 'verify_ssl',             'value' => '0',                'type' => 'bool',   'label' => 'Проверять SSL'],

            ['group' => 'security',  'key' => 'jwt_expires_days',       'value' => '7',                'type' => 'int',    'label' => 'Срок JWT (дни)'],
            ['group' => 'security',  'key' => 'rate_limit_enabled',     'value' => '1',                'type' => 'bool',   'label' => 'Rate limiting'],
            ['group' => 'security',  'key' => 'rate_limit_per_minute',  'value' => '60',               'type' => 'int',    'label' => 'Запросов в минуту'],

            ['group' => 'relevance', 'key' => 'auto_disable_stale',     'value' => '0',                'type' => 'bool',   'label' => 'Авто-отключение устаревших'],
            ['group' => 'relevance', 'key' => 'stale_days',             'value' => '30',               'type' => 'int',    'label' => 'Дней до устаревания'],
        ];

        foreach ($defaults as $s) {
            Setting::firstOrCreate(
                ['key' => $s['key']],
                $s
            );
        }

        $this->command->info('Seeder: создан admin + ' . count($defaults) . ' настроек');
    }
}
