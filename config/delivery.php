<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Точка отправления по умолчанию (маркетплейс без собственного склада)
    |--------------------------------------------------------------------------
    |
    | Пока у продавца в карточке нет отдельной зоны отгрузки, считаем отправку
    | из Москвы (Садовод как рынок также в МСК). При необходимости CRM
    | интеграция СДЭК может переопределить код города (sender_city_code).
    |
    */
    'origin' => [
        'cdek_city_code' => (int) env('DELIVERY_ORIGIN_CDEK_CITY_CODE', 44),
        'postal_index' => (string) env('DELIVERY_ORIGIN_POSTAL_INDEX', '101000'),
    ],

    'package_defaults' => [
        'weight_g' => max(1, (int) env('DELIVERY_DEFAULT_WEIGHT_G', 500)),
        'length_cm' => max(1, (int) env('DELIVERY_DEFAULT_LENGTH_CM', 20)),
        'width_cm' => max(1, (int) env('DELIVERY_DEFAULT_WIDTH_CM', 15)),
        'height_cm' => max(1, (int) env('DELIVERY_DEFAULT_HEIGHT_CM', 10)),
    ],

    /** Точка отправления по умолчанию для Яндекс Доставки (экспресс offers/calculate). */
    'yandex_delivery' => [
        'default_sender' => [
            'lat' => (float) env('YANDEX_DELIVERY_SENDER_LAT', 55.6534),
            'lng' => (float) env('YANDEX_DELIVERY_SENDER_LNG', 37.7201),
        ],
    ],
];
