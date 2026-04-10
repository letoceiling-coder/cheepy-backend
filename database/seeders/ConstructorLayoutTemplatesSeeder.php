<?php

namespace Database\Seeders;

use App\Models\ConstructorLayoutTemplate;
use App\Models\ConstructorLayoutTemplateBlock;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Шаблоны конструктора витрины. Главная — из database/data/homepage_layout_spec.json.
 * Повторный запуск пропускает существующие system:*, если не задано CONSTRUCTOR_SEED_OVERWRITE=true.
 */
class ConstructorLayoutTemplatesSeeder extends Seeder
{
    public function run(): void
    {
        $overwrite = filter_var((string) env('CONSTRUCTOR_SEED_OVERWRITE', ''), FILTER_VALIDATE_BOOL);

        foreach ($this->definitions() as $def) {
            $exists = ConstructorLayoutTemplate::query()->where('template_key', $def['key'])->exists();
            if ($exists && ! $overwrite) {
                $this->command->info('ConstructorLayoutTemplatesSeeder: пропуск '.$def['key'].' (есть в БД)');

                continue;
            }

            DB::transaction(function () use ($def) {
                $tpl = ConstructorLayoutTemplate::query()->updateOrCreate(
                    ['template_key' => $def['key']],
                    [
                        'name' => $def['name'],
                        'description' => $def['description'] ?? null,
                        'is_system' => true,
                        'sort_order' => $def['sort_order'] ?? 0,
                    ]
                );

                $tpl->blocks()->delete();

                $rows = match ($def['blocks_source'] ?? null) {
                    'homepage_layout_spec' => $this->loadHomepageRows(),
                    default => $def['blocks'] ?? [],
                };

                foreach ($rows as $i => $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    $type = $row['type'] ?? $row['block_type'] ?? null;
                    if (! is_string($type) || $type === '') {
                        continue;
                    }
                    $settings = $row['settings'] ?? [];
                    if (! is_array($settings)) {
                        $settings = [];
                    }
                    $keySlug = preg_replace('/[^a-z0-9]+/i', '-', $def['key']);
                    ConstructorLayoutTemplateBlock::query()->create([
                        'constructor_layout_template_id' => $tpl->id,
                        'block_type' => $type,
                        'sort_order' => $row['sort_order'] ?? ($i * 10),
                        'settings' => $settings,
                        'client_key' => sprintf('seed-%s-%03d-%s', $keySlug, $i, $type),
                        'is_visible' => $row['is_visible'] ?? true,
                    ]);
                }
            });

            $this->command->info('ConstructorLayoutTemplatesSeeder: '.$def['key']);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadHomepageRows(): array
    {
        $path = database_path('data/homepage_layout_spec.json');
        if (! File::exists($path)) {
            $this->command->warn('ConstructorLayoutTemplatesSeeder: нет файла '.$path);

            return [];
        }
        $rows = json_decode(File::get($path), true);

        return is_array($rows) ? $rows : [];
    }

    /**
     * @return list<array{key: string, name: string, sort_order?: int, description?: string|null, blocks_source?: string, blocks?: list<array<string, mixed>>}>
     */
    private function definitions(): array
    {
        $embed = static fn (string $path, string $caption, int $h = 800) => [
            'type' => 'LivePageEmbed',
            'settings' => ['path' => $path, 'minHeight' => $h, 'caption' => $caption],
        ];

        return [
            [
                'key' => 'system:homepage',
                'name' => 'Главная страница (как на сайте)',
                'sort_order' => 10,
                'blocks_source' => 'homepage_layout_spec',
            ],
            [
                'key' => 'system:product',
                'name' => 'Карточка товара',
                'sort_order' => 20,
                'blocks' => [
                    ['type' => 'Header'],
                    ['type' => 'ProductPageBreadcrumbs'],
                    ['type' => 'ProductDetailHero'],
                    ['type' => 'ProductDetailTabsSection'],
                    ['type' => 'ProductSellerCardSection'],
                    ['type' => 'ProductRecentlyViewedSection'],
                    ['type' => 'ProductBuyTogetherSection'],
                    ['type' => 'ProductSimilarProductsSection'],
                    ['type' => 'Footer'],
                    ['type' => 'MobileBottomNav'],
                ],
            ],
            [
                'key' => 'system:category',
                'name' => 'Категория',
                'sort_order' => 30,
                'blocks' => [
                    ['type' => 'Header'],
                    ['type' => 'CategoryPageBreadcrumbs'],
                    ['type' => 'CategoryHeroBanner'],
                    ['type' => 'CategoryListingContent'],
                    ['type' => 'Footer'],
                    ['type' => 'MobileBottomNav'],
                ],
            ],
            [
                'key' => 'system:cart',
                'name' => 'Корзина',
                'sort_order' => 40,
                'blocks' => [
                    ['type' => 'Header'],
                    ['type' => 'CartPageContent'],
                    ['type' => 'Footer'],
                    ['type' => 'MobileBottomNav'],
                ],
            ],
            [
                'key' => 'system:favorites',
                'name' => 'Избранное',
                'sort_order' => 50,
                'blocks' => [
                    ['type' => 'Header'],
                    ['type' => 'FavoritesPageContent'],
                    ['type' => 'Footer'],
                    ['type' => 'MobileBottomNav'],
                ],
            ],
            [
                'key' => 'system:auth',
                'name' => 'Вход и регистрация',
                'sort_order' => 60,
                'blocks' => [
                    ['type' => 'Header'],
                    ['type' => 'AuthPageContent'],
                    ['type' => 'Footer'],
                    ['type' => 'MobileBottomNav'],
                ],
            ],
            [
                'key' => 'system:brands',
                'name' => 'Каталог (бренды)',
                'sort_order' => 70,
                'blocks' => [
                    ['type' => 'Header'],
                    ['type' => 'BrandsListBreadcrumbs'],
                    ['type' => 'BrandsListHero'],
                    ['type' => 'BrandsListPopularSection'],
                    ['type' => 'BrandsListAllSection'],
                    ['type' => 'BrandsListInfoSection'],
                    ['type' => 'Footer'],
                    ['type' => 'MobileBottomNav'],
                ],
            ],
            [
                'key' => 'system:page-delivery',
                'name' => 'Доставка',
                'sort_order' => 100,
                'blocks' => [$embed('/delivery', 'Доставка')],
            ],
            [
                'key' => 'system:page-rules',
                'name' => 'Правила площадки',
                'sort_order' => 110,
                'blocks' => [$embed('/rules', 'Правила площадки')],
            ],
            [
                'key' => 'system:page-faq',
                'name' => 'Вопросы и ответы',
                'sort_order' => 120,
                'blocks' => [$embed('/faq', 'FAQ')],
            ],
            [
                'key' => 'system:page-sell',
                'name' => 'Начните продавать на Cheepy',
                'sort_order' => 130,
                'blocks' => [$embed('/sell', 'Продавать')],
            ],
            [
                'key' => 'system:page-commission',
                'name' => 'Комиссия',
                'sort_order' => 140,
                'blocks' => [$embed('/commission', 'Комиссия')],
            ],
            [
                'key' => 'system:page-seller-help',
                'name' => 'Помощь продавцам',
                'sort_order' => 150,
                'blocks' => [$embed('/seller-help', 'Помощь продавцам')],
            ],
            [
                'key' => 'system:page-returns',
                'name' => 'Возврат товара',
                'sort_order' => 160,
                'blocks' => [$embed('/returns', 'Возврат')],
            ],
            [
                'key' => 'system:page-payment',
                'name' => 'Способы оплаты',
                'sort_order' => 170,
                'blocks' => [$embed('/payment', 'Оплата')],
            ],
            [
                'key' => 'system:page-how-to-order',
                'name' => 'Как сделать заказ',
                'sort_order' => 180,
                'blocks' => [$embed('/how-to-order', 'Как заказать')],
            ],
            [
                'key' => 'system:page-about',
                'name' => 'О компании',
                'sort_order' => 190,
                'blocks' => [$embed('/about', 'О компании')],
            ],
            [
                'key' => 'system:page-contacts',
                'name' => 'Контакты',
                'sort_order' => 200,
                'blocks' => [$embed('/contacts', 'Контакты')],
            ],
            [
                'key' => 'system:page-careers',
                'name' => 'Вакансии',
                'sort_order' => 210,
                'blocks' => [$embed('/careers', 'Вакансии')],
            ],
            [
                'key' => 'system:page-blog',
                'name' => 'Блог',
                'sort_order' => 220,
                'blocks' => [$embed('/blog', 'Блог')],
            ],
        ];
    }
}
