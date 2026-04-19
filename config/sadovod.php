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
     * Broadcast ProductParsed event once per N saved products.
     * 50 — компромисс между плавностью прогресс-бара в админке и количеством
     * Reverb-публикаций (на 10к товаров при 20 было 500 публикаций, при 50 — 200).
     */
    'product_broadcast_every' => (int) env('SADAVOD_PRODUCT_BROADCAST_EVERY', 50),

    // Внимание: download_medium теперь живёт в parser_settings (управляется из /admin).
    // env/config-флага больше нет, настройка применяется сразу и не зависит от деплоя.
];
