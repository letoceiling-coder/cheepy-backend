<?php

return [
    /*
     * Базовый URL сайта-донора
     */
    'base_url' => env('SADAVOD_DONOR_URL', 'https://sadovodbaza.ru'),

    /*
     * Задержка между HTTP-запросами в миллисекундах (persistent connections + rate limit)
     */
    'request_delay_ms' => (int) env('SADAVOD_REQUEST_DELAY_MS', 200),

    /*
     * User-Agent для запросов
     */
    'user_agent' => env('SADAVOD_USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'),

    /*
     * Проверка SSL-сертификата
     */
    'verify_ssl' => filter_var(env('SADAVOD_VERIFY_SSL', false), FILTER_VALIDATE_BOOLEAN),

    /*
     * Исключить ссылки из меню по паттернам URL
     */
    'exclude_menu_links' => [
        '/blog', '/news', '/contacts',
    ],

    /*
     * Исключить ссылки из меню по тексту
     */
    'exclude_menu_text' => [
        'Блог', 'Новости', 'Контакты',
    ],

    /*
     * Папка для хранения скачанных фото (относительно storage/app)
     */
    'photos_dir' => 'photos',

    /*
     * Максимальное количество фото на продукт (0=все)
     */
    'max_photos_per_product' => (int) env('SADAVOD_MAX_PHOTOS', 0),

    /*
     * Products dispatched per batch in ParseCategoryJob before a 200ms pause.
     * Prevents queue explosion on large categories (e.g. 6000+ products).
     */
    'dispatch_batch_size' => (int) env('SADAVOD_DISPATCH_BATCH_SIZE', 50),

    /*
     * Max category pages per category (safety limit; pagination stops when page returns 0 products).
     */
    'max_category_pages' => (int) env('SADAVOD_MAX_CATEGORY_PAGES', 200),
];
