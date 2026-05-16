<?php

namespace App\Services\Delivery;

/**
 * Конфигурация хостов и путей API Яндекс Доставки.
 *
 * @see https://yandex.com/support/delivery-profile/ru/api/
 * @see https://yandex.ru/support/delivery-profile/ru/api/express/openapi/IntegrationV2OfferCalculate
 * @see https://yandex.ru/support/delivery-profile/ru/api/other-day/
 */
class YandexDeliveryConfig
{
    public const ENV_PRODUCTION = 'production';

    public const ENV_TEST = 'test';

    /** Экспресс / доставка в течение дня (РФ): b2b.taxi.yandex.net */
    public const EXPRESS_API_BASE_PRODUCTION = 'https://b2b.taxi.yandex.net';

    /** Доставка по России — тестовый контур */
    public const OTHER_DAY_API_BASE_TEST = 'https://b2b.taxi.tst.yandex.net';

    /** Доставка по России — боевой контур */
    public const OTHER_DAY_API_BASE_PRODUCTION = 'https://b2b-authproxy.taxi.yandex.net';

    public const EXPRESS_OFFERS_CALCULATE_PATH = '/b2b/cargo/integration/v2/offers/calculate';

    public const OTHER_DAY_PRICING_PATH = '/api/b2b/platform/pricing-calculator';

    public static function expressApiBase(string $environment): string
    {
        return self::EXPRESS_API_BASE_PRODUCTION;
    }

    public static function otherDayApiBase(string $environment): string
    {
        return $environment === self::ENV_TEST
            ? self::OTHER_DAY_API_BASE_TEST
            : self::OTHER_DAY_API_BASE_PRODUCTION;
    }

    public static function docsUrl(): string
    {
        return 'https://yandex.com/support/delivery-profile/ru/api/';
    }
}
