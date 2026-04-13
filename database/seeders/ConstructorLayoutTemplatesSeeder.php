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
    private const GLOBAL_PAGE_KEY = 'global:chrome';
    private const GLOBAL_BLOCK_TYPES = ['Header', 'Footer', 'MobileBottomNav'];

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
                        'template_type' => $def['template_type'] ?? 'content',
                        'page_scope' => $def['page_scope'] ?? 'page',
                        'page_key' => $def['page_key'] ?? null,
                        'is_system' => ($def['template_type'] ?? 'content') === 'system',
                        'is_editable' => $def['is_editable'] ?? true,
                        'is_active' => true,
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
                        'is_enabled' => $row['is_enabled'] ?? true,
                        'is_visible' => $row['is_enabled'] ?? true,
                        'is_required' => $row['is_required'] ?? false,
                        'is_locked' => $row['is_locked'] ?? false,
                        'slot_key' => $row['slot_key'] ?? null,
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

        if (! is_array($rows)) {
            return [];
        }

        return array_values(array_filter($rows, function ($row) {
            if (! is_array($row)) {
                return false;
            }
            $type = $row['type'] ?? $row['block_type'] ?? '';

            return ! in_array($type, self::GLOBAL_BLOCK_TYPES, true);
        }));
    }

    /**
     * @return list<array{key: string, name: string, sort_order?: int, description?: string|null, blocks_source?: string, template_type?: string, page_scope?: string, page_key?: string|null, is_editable?: bool, blocks?: list<array<string, mixed>>}>
     */
    private function definitions(): array
    {
        $embed = static fn (string $path, string $caption, int $h = 800) => [
            'type' => 'LivePageEmbed',
            'settings' => ['path' => $path, 'minHeight' => $h, 'caption' => $caption],
        ];

        return [
            [
                'key' => self::GLOBAL_PAGE_KEY,
                'name' => 'Глобальный layout (Header/Footer)',
                'template_type' => 'system',
                'page_scope' => 'global',
                'page_key' => self::GLOBAL_PAGE_KEY,
                'sort_order' => 1,
                'blocks' => [
                    ['type' => 'Header', 'is_required' => true, 'is_locked' => true, 'slot_key' => 'global_header'],
                    ['type' => 'Footer', 'is_required' => true, 'is_locked' => true, 'slot_key' => 'global_footer'],
                    ['type' => 'MobileBottomNav', 'is_required' => true, 'is_locked' => true, 'slot_key' => 'global_mobile_nav'],
                ],
            ],
            [
                'key' => 'system:homepage',
                'name' => 'Главная страница (как на сайте)',
                'template_type' => 'system',
                'page_scope' => 'page',
                'page_key' => 'system:homepage',
                'sort_order' => 10,
                'blocks_source' => 'homepage_layout_spec',
            ],
            [
                'key' => 'system:product',
                'name' => 'Карточка товара',
                'template_type' => 'system',
                'page_scope' => 'page',
                'page_key' => 'system:product',
                'sort_order' => 20,
                'blocks' => [
                    ['type' => 'ProductPageBreadcrumbs', 'is_required' => true, 'slot_key' => 'product_breadcrumbs'],
                    ['type' => 'ProductDetailHero', 'is_required' => true, 'slot_key' => 'product_hero'],
                    ['type' => 'ProductDetailTabsSection', 'is_required' => true, 'slot_key' => 'product_tabs'],
                    ['type' => 'ProductSellerCardSection', 'is_required' => true, 'slot_key' => 'product_seller'],
                    ['type' => 'ProductRecentlyViewedSection', 'slot_key' => 'product_recently_viewed'],
                    ['type' => 'ProductBuyTogetherSection', 'slot_key' => 'product_buy_together'],
                    ['type' => 'ProductSimilarProductsSection', 'slot_key' => 'product_similar'],
                ],
            ],
            [
                'key' => 'system:category',
                'name' => 'Категория',
                'template_type' => 'system',
                'page_scope' => 'page',
                'page_key' => 'system:category',
                'sort_order' => 30,
                'blocks' => [
                    ['type' => 'CategoryPageBreadcrumbs', 'is_required' => true, 'slot_key' => 'category_breadcrumbs'],
                    ['type' => 'CategoryHeroBanner', 'is_required' => true, 'slot_key' => 'category_hero'],
                    ['type' => 'CategoryListingContent', 'is_required' => true, 'slot_key' => 'category_listing'],
                ],
            ],
            [
                'key' => 'system:cart',
                'name' => 'Корзина',
                'template_type' => 'system',
                'page_scope' => 'page',
                'page_key' => 'system:cart',
                'sort_order' => 40,
                'blocks' => [
                    ['type' => 'CartPageContent', 'is_required' => true, 'slot_key' => 'cart_content'],
                ],
            ],
            [
                'key' => 'system:favorites',
                'name' => 'Избранное',
                'template_type' => 'system',
                'page_scope' => 'page',
                'page_key' => 'system:favorites',
                'sort_order' => 50,
                'blocks' => [
                    ['type' => 'FavoritesPageContent', 'is_required' => true, 'slot_key' => 'favorites_content'],
                ],
            ],
            [
                'key' => 'system:auth',
                'name' => 'Вход и регистрация',
                'template_type' => 'system',
                'page_scope' => 'page',
                'page_key' => 'system:auth',
                'sort_order' => 60,
                'blocks' => [
                    ['type' => 'AuthPageContent', 'is_required' => true, 'slot_key' => 'auth_content'],
                ],
            ],
            [
                'key' => 'system:brands',
                'name' => 'Каталог (бренды)',
                'template_type' => 'system',
                'page_scope' => 'page',
                'page_key' => 'system:brands',
                'sort_order' => 70,
                'blocks' => [
                    ['type' => 'BrandsListBreadcrumbs', 'is_required' => true, 'slot_key' => 'brands_breadcrumbs'],
                    ['type' => 'BrandsListHero', 'is_required' => true, 'slot_key' => 'brands_hero'],
                    ['type' => 'BrandsListPopularSection', 'slot_key' => 'brands_popular'],
                    ['type' => 'BrandsListAllSection', 'is_required' => true, 'slot_key' => 'brands_all'],
                    ['type' => 'BrandsListInfoSection', 'slot_key' => 'brands_info'],
                ],
            ],
            [
                'key' => 'system:page-delivery',
                'name' => 'Доставка',
                'template_type' => 'content',
                'page_scope' => 'page',
                'page_key' => 'content:delivery',
                'sort_order' => 100,
                'blocks' => [$embed('/delivery', 'Доставка')],
            ],
            [
                'key' => 'system:page-rules',
                'name' => 'Правила площадки',
                'template_type' => 'content',
                'page_scope' => 'page',
                'page_key' => 'content:rules',
                'sort_order' => 110,
                'blocks' => [$embed('/rules', 'Правила площадки')],
            ],
            [
                'key' => 'system:page-faq',
                'name' => 'Вопросы и ответы',
                'template_type' => 'content',
                'page_scope' => 'page',
                'page_key' => 'content:faq',
                'sort_order' => 120,
                'blocks' => [$embed('/faq', 'FAQ')],
            ],
            [
                'key' => 'system:page-sell',
                'name' => 'Начните продавать на Cheepy',
                'template_type' => 'content',
                'page_scope' => 'page',
                'page_key' => 'content:sell',
                'sort_order' => 130,
                'blocks' => [$embed('/sell', 'Продавать')],
            ],
            [
                'key' => 'system:page-commission',
                'name' => 'Комиссия',
                'template_type' => 'content',
                'page_scope' => 'page',
                'page_key' => 'content:commission',
                'sort_order' => 140,
                'blocks' => [$embed('/commission', 'Комиссия')],
            ],
            [
                'key' => 'system:page-seller-help',
                'name' => 'Помощь продавцам',
                'template_type' => 'content',
                'page_scope' => 'page',
                'page_key' => 'content:seller-help',
                'sort_order' => 150,
                'blocks' => [$embed('/seller-help', 'Помощь продавцам')],
            ],
            [
                'key' => 'system:page-returns',
                'name' => 'Возврат товара',
                'template_type' => 'content',
                'page_scope' => 'page',
                'page_key' => 'content:returns',
                'sort_order' => 160,
                'blocks' => [$embed('/returns', 'Возврат')],
            ],
            [
                'key' => 'system:page-payment',
                'name' => 'Способы оплаты',
                'template_type' => 'content',
                'page_scope' => 'page',
                'page_key' => 'content:payment',
                'sort_order' => 170,
                'blocks' => [$embed('/payment', 'Оплата')],
            ],
            [
                'key' => 'system:page-how-to-order',
                'name' => 'Как сделать заказ',
                'template_type' => 'content',
                'page_scope' => 'page',
                'page_key' => 'content:how-to-order',
                'sort_order' => 180,
                'blocks' => [$embed('/how-to-order', 'Как заказать')],
            ],
            [
                'key' => 'system:page-about',
                'name' => 'О компании',
                'template_type' => 'content',
                'page_scope' => 'page',
                'page_key' => 'content:about',
                'sort_order' => 190,
                'blocks' => [$embed('/about', 'О компании')],
            ],
            [
                'key' => 'system:page-contacts',
                'name' => 'Контакты',
                'template_type' => 'content',
                'page_scope' => 'page',
                'page_key' => 'content:contacts',
                'sort_order' => 200,
                'blocks' => [$embed('/contacts', 'Контакты')],
            ],
            [
                'key' => 'system:page-careers',
                'name' => 'Вакансии',
                'template_type' => 'content',
                'page_scope' => 'page',
                'page_key' => 'content:careers',
                'sort_order' => 210,
                'blocks' => [$embed('/careers', 'Вакансии')],
            ],
            [
                'key' => 'system:page-blog',
                'name' => 'Блог',
                'template_type' => 'content',
                'page_scope' => 'page',
                'page_key' => 'content:blog',
                'sort_order' => 220,
                'blocks' => [$embed('/blog', 'Блог')],
            ],
        ];
    }
}
