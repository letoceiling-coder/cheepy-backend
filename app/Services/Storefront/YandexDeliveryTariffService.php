<?php

namespace App\Services\Storefront;

use App\Models\DeliveryIntegration;
use App\Models\UserAddress;
use App\Services\Delivery\YandexDeliveryConfig;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Расчёт тарифов Яндекс Доставки для витрины.
 *
 * Экспресс: POST …/offers/calculate
 * Доставка по России: POST …/pricing-calculator (при platform_station_id склада)
 */
class YandexDeliveryTariffService
{
    public function __construct(
        private readonly YandexRuAddressEnrichmentService $geocoder,
    ) {
    }

  /**
   * @return array{ok: bool, message?: string, quote?: array<string, mixed>, quotes?: list<array<string, mixed>>}
   */
  public function quote(
      UserAddress $destination,
      int $weightG,
      int $lengthCm,
      int $widthCm,
      int $heightCm,
      ?int $declaredValueKopeks = null,
  ): array {
      $integration = DeliveryIntegration::query()->where('name', 'yandex_delivery')->first();
      $config = $integration?->config ?? [];
      if ($integration === null || ! $integration->is_active) {
          return ['ok' => false, 'message' => 'Яндекс Доставка отключена'];
      }

      $token = trim((string) ($config['oauth_token'] ?? ''));
      if ($token === '') {
          return ['ok' => false, 'message' => 'Укажите OAuth-токен в интеграции Яндекс Доставка'];
      }

      $modes = $this->enabledModes($config);
      /** @var list<array<string, mixed>> $quotes */
      $quotes = [];
      $failures = [];

      if (in_array('express', $modes, true)) {
          $express = $this->quoteExpress($config, $token, $destination, $weightG, $lengthCm, $widthCm, $heightCm);
          if (! empty($express['ok']) && isset($express['quote'])) {
              $quotes[] = $express['quote'];
          } elseif (($express['message'] ?? '') !== '') {
              $failures[] = (string) $express['message'];
          }
      }

      if (in_array('other_day', $modes, true)) {
          $other = $this->quoteOtherDay($config, $token, $destination, $weightG, $lengthCm, $widthCm, $heightCm, $declaredValueKopeks);
          if (! empty($other['ok']) && isset($other['quote'])) {
              $quotes[] = $other['quote'];
          } elseif (($other['message'] ?? '') !== '') {
              $failures[] = (string) $other['message'];
          }
      }

      if ($quotes === []) {
          $message = $this->summarizeQuoteFailures($failures, $config);

          return ['ok' => false, 'message' => $message];
      }

      usort($quotes, fn ($a, $b) => ((float) ($a['price_rub'] ?? 0)) <=> ((float) ($b['price_rub'] ?? 0)));

      return ['ok' => true, 'quotes' => $quotes, 'quote' => $quotes[0]];
  }

  /**
   * Проверка токена из CRM (экспресс offers/calculate с тестовым маршрутом).
   *
   * @return array{success: bool, message: string}
   */
  public function testConnection(): array
  {
      $integration = DeliveryIntegration::query()->where('name', 'yandex_delivery')->first();
      $config = $integration?->config ?? [];

      if (! $integration?->is_active) {
          return ['success' => false, 'message' => 'Включите интеграцию переключателем ниже.'];
      }

      $token = trim((string) ($config['oauth_token'] ?? ''));
      if ($token === '') {
          return ['success' => false, 'message' => 'Укажите OAuth-токен (личный кабинет → Интеграции → Получить токен).'];
      }

      $env = $this->resolveEnvironment($config);
      $modes = $this->enabledModes($config);
      $okParts = [];
      $failParts = [];

      if (in_array('express', $modes, true)) {
          $sender = $this->resolveSenderPoint($config);
          $destCoords = $this->defaultTestDestinationCoords();
          $body = $this->buildExpressCalculateBody(
              $sender,
              [
                  'id' => 2,
                  'coordinates' => $destCoords,
                  'fullname' => 'Москва, Тверская улица, 1',
                  'country' => 'Россия',
                  'city' => 'Москва',
              ],
              500,
              20,
              15,
              10,
          );

          $res = $this->postExpressCalculate($token, $env, $body);
          if ($res['ok']) {
              $okParts[] = 'Экспресс: OK (offers/calculate, '.($res['offers_count'] ?? 0).' вариантов)';
          } else {
              $failParts[] = 'Экспресс: '.($res['message'] ?? 'ошибка');
          }
      }

      if (in_array('other_day', $modes, true)) {
          $stationId = trim((string) ($config['platform_station_id'] ?? ''));
          if ($stationId === '') {
              $failParts[] = 'Доставка по России: укажите platform_station_id склада (выдаёт менеджер Яндекс Доставки)';
          } else {
              $pricing = $this->quoteOtherDay($config, $token, null, 500, 20, 15, 10, null, 'Москва, Тверская улица, 1');
              if (! empty($pricing['ok'])) {
                  $okParts[] = 'Доставка по России: OK (pricing-calculator)';
              } else {
                  $raw = (string) ($pricing['message'] ?? 'ошибка');
                  $failParts[] = str_starts_with($raw, 'Доставка по России') ? $raw : 'Доставка по России: '.$raw;
              }
          }
      }

      if ($okParts === [] && $failParts === []) {
          return ['success' => false, 'message' => 'Выберите хотя бы один режим API (express и/или other_day в поле «Режимы API»).'];
      }

      if ($okParts !== []) {
          $integration->update(['last_successful_auth_at' => now()]);
          $message = implode('. ', $okParts).'.';
          if ($failParts !== []) {
              $message .= ' Не прошло: '.implode('; ', $failParts).'.';
          }

          return ['success' => true, 'message' => $message];
      }

      return ['success' => false, 'message' => implode('; ', $failParts)];
  }

  /**
   * @param  array<string, mixed>  $config
   * @return list<string>
   */
  private function enabledModes(array $config): array
  {
      $raw = trim((string) ($config['api_modes'] ?? 'express,other_day'));
      $modes = array_values(array_filter(array_map('trim', explode(',', $raw))));
      if ($modes === []) {
          return ['express', 'other_day'];
      }

      return $modes;
  }

  /**
   * @param  array<string, mixed>  $config
   * @return array{ok: bool, message?: string, quote?: array<string, mixed>}
   */
  private function quoteExpress(
      array $config,
      string $token,
      UserAddress $destination,
      int $weightG,
      int $lengthCm,
      int $widthCm,
      int $heightCm,
  ): array {
      $sender = $this->resolveSenderPoint($config);
      $dest = $this->resolveDestinationPoint($destination);
      if ($dest === null) {
          return ['ok' => false, 'message' => 'Не удалось определить координаты адреса доставки'];
      }

      $body = $this->buildExpressCalculateBody($sender, $dest, $weightG, $lengthCm, $widthCm, $heightCm);
      $env = $this->resolveEnvironment($config);
      $res = $this->postExpressCalculate($token, $env, $body);
      if (! $res['ok']) {
          return ['ok' => false, 'message' => $res['message']];
      }

      $offer = $res['cheapest_offer'] ?? null;
      if (! is_array($offer)) {
          return ['ok' => false, 'message' => 'Яндекс Доставка: нет доступных вариантов экспресс-доставки'];
      }

      $priceRub = $this->extractOfferPriceRub($offer);
      if ($priceRub === null) {
          return ['ok' => false, 'message' => 'Яндекс Доставка: не удалось прочитать цену'];
      }

      $taxiClass = (string) ($offer['taxi_class'] ?? $offer['description'] ?? 'express');

      return [
          'ok' => true,
          'quote' => [
              'integration' => 'yandex_delivery',
              'provider_title' => 'Яндекс Доставка',
              'service_code' => 'yandex_express_'.$taxiClass,
              'service_name' => 'Экспресс · '.($offer['description'] ?? $taxiClass),
              'delivery_mode' => 'express',
              'price_rub' => $priceRub,
              'period_min_days' => 0,
              'period_max_days' => 1,
          ],
      ];
  }

  /**
   * @param  array<string, mixed>  $config
   * @return array{ok: bool, message?: string, quote?: array<string, mixed>}
   */
  private function quoteOtherDay(
      array $config,
      string $token,
      ?UserAddress $destination,
      int $weightG,
      int $lengthCm,
      int $widthCm,
      int $heightCm,
      ?int $declaredValueKopeks,
      ?string $overrideAddress = null,
  ): array {
      $stationId = trim((string) ($config['platform_station_id'] ?? ''));
      if ($stationId === '') {
          return ['ok' => false, 'message' => 'Не задан platform_station_id склада'];
      }

      $address = $overrideAddress;
      if ($address === null && $destination !== null) {
          $address = $this->formatOtherDayDestinationAddress($destination);
      }
      if ($address === null || trim($address) === '') {
          return ['ok' => false, 'message' => 'Нужен адрес получателя'];
      }

      $env = $this->resolveEnvironment($config);
      $base = YandexDeliveryConfig::otherDayApiBase($env);
      $tariff = trim((string) ($config['other_day_tariff'] ?? 'time_interval'));
      if ($tariff === '') {
          $tariff = 'time_interval';
      }

      $payload = [
          'source' => ['platform_station_id' => $stationId],
          'destination' => ['address' => $address],
          'tariff' => $tariff,
          'total_weight' => max(1, $weightG),
          'total_assessed_price' => max(0, (int) ($declaredValueKopeks ?? 0)),
          'client_price' => 0,
          'payment_method' => 'already_paid',
          'places' => [[
              'physical_dims' => [
                  'weight_gross' => max(1, $weightG),
                  'dx' => max(1, $lengthCm),
                  'dy' => max(1, $heightCm),
                  'dz' => max(1, $widthCm),
              ],
          ]],
      ];

      try {
          $res = Http::withToken($token)
              ->acceptJson()
              ->withHeaders(['Accept-Language' => 'ru'])
              ->timeout(25)
              ->post($base.YandexDeliveryConfig::OTHER_DAY_PRICING_PATH, $payload);
      } catch (\Throwable $e) {
          return ['ok' => false, 'message' => 'Яндекс Доставка: '.$e->getMessage()];
      }

      if (! $res->successful()) {
          return ['ok' => false, 'message' => $this->formatOtherDayHttpError($res->status(), $res->json())];
      }

      $j = $res->json();
      if (! is_array($j)) {
          return ['ok' => false, 'message' => 'Яндекс Доставка: пустой ответ'];
      }

      $pricingTotal = trim((string) ($j['pricing_total'] ?? ''));
      $priceRub = $this->parseRubString($pricingTotal);
      if ($priceRub === null) {
          return ['ok' => false, 'message' => 'Яндекс Доставка: не удалось разобрать pricing_total'];
      }

      $days = max(1, (int) ($j['delivery_days'] ?? 3));

      return [
          'ok' => true,
          'quote' => [
              'integration' => 'yandex_delivery',
              'provider_title' => 'Яндекс Доставка',
              'service_code' => 'yandex_other_day_'.$tariff,
              'service_name' => $tariff === 'self_pickup' ? 'Доставка до ПВЗ' : 'Доставка по России',
              'delivery_mode' => 'other_day',
              'price_rub' => $priceRub,
              'period_min_days' => $days,
              'period_max_days' => $days,
          ],
      ];
  }

  /**
   * @param  array<string, mixed>  $config
   * @return array{id: int, coordinates: list<float>, fullname: string, country: string, city: string}
   */
  private function resolveSenderPoint(array $config): array
  {
      $lng = $this->toFloat($config['sender_lng'] ?? null);
      $lat = $this->toFloat($config['sender_lat'] ?? null);
      if ($lng === null || $lat === null) {
          $defaults = config('delivery.yandex_delivery.default_sender', []);
          $lng = (float) ($defaults['lng'] ?? 37.6225);
          $lat = (float) ($defaults['lat'] ?? 55.7532);
      }

      $city = trim((string) ($config['sender_city'] ?? 'Москва'));
      $line = trim((string) ($config['sender_address'] ?? 'Садовод'));
      $fullname = trim((string) ($config['sender_fullname'] ?? ''));
      if ($fullname === '') {
          $fullname = $city.', '.$line;
      }

      return [
          'id' => 1,
          'coordinates' => [$lng, $lat],
          'fullname' => $fullname,
          'country' => 'Россия',
          'city' => $city !== '' ? $city : 'Москва',
      ];
  }

  /**
   * @return array{id: int, coordinates: list<float>, fullname: string, country: string, city: string}|null
   */
  private function formatOtherDayDestinationAddress(UserAddress $destination): string
  {
      $payload = is_array($destination->provider_payload) ? $destination->provider_payload : [];
      $formatted = trim((string) ($payload['yandex_geocode']['formatted'] ?? ''));

      $enriched = $this->geocoder->enrichValidatedAddress([
          'country' => 'Россия',
          'region' => $destination->region,
          'city' => (string) $destination->city,
          'line1' => (string) $destination->line1,
          'line2' => $destination->line2,
          'postal_code' => $destination->postal_code,
          'lat' => $destination->lat,
          'lng' => $destination->lng,
          'provider_payload' => $payload,
      ]);

      $enrichedFormatted = trim((string) ($enriched['provider_payload']['yandex_geocode']['formatted'] ?? ''));
      if (mb_strlen($enrichedFormatted) > mb_strlen($formatted)) {
          $formatted = $enrichedFormatted;
      }

      $line1 = trim((string) $destination->line1);
      if ($formatted !== '' && $line1 !== '') {
          if (mb_stripos($formatted, $line1) === false) {
              $formatted .= ', '.$line1;
          }

          return $formatted;
      }

      $postal = preg_replace('/\D/', '', (string) ($enriched['postal_code'] ?? $destination->postal_code ?? ''));
      $region = trim((string) ($enriched['region'] ?? $destination->region ?? ''));
      $city = trim((string) ($enriched['city'] ?? $destination->city ?? ''));
      $parts = [];
      if (strlen($postal) === 6) {
          $parts[] = $postal;
      }
      if ($region !== '') {
          $parts[] = $region;
      }
      if ($city !== '') {
          $parts[] = $city;
      }
      if ($line1 !== '') {
          $parts[] = $line1;
      }

      return implode(', ', $parts);
  }

  /**
   * @param  list<string>  $failures
   * @param  array<string, mixed>  $config
   */
  private function summarizeQuoteFailures(array $failures, array $config): string
  {
      $joined = implode('; ', array_values(array_filter($failures)));
      foreach ($failures as $f) {
          if (str_contains($f, 'no_delivery_options') || str_contains($f, 'No delivery options')) {
              if ($this->resolveEnvironment($config) === YandexDeliveryConfig::ENV_TEST) {
                  return 'Яндекс Доставка: на этот адрес нет тарифа в тестовом контуре (склад из документации — только Москва). Для регионов подключите боевой platform_station_id склада Садовода.';
              }

              return 'Яндекс Доставка: доставка на указанный адрес недоступна для склада отправления.';
          }
      }

      if ($joined !== '') {
          return 'Яндекс Доставка: '.$joined;
      }

      return 'Яндекс Доставка: не удалось рассчитать тариф для адреса';
  }

  /**
   * @param  mixed  $json
   */
  private function formatOtherDayHttpError(int $status, $json): string
  {
      $code = is_array($json) ? trim((string) ($json['code'] ?? '')) : '';
      if ($status === 400 && $code === 'no_delivery_options') {
          return 'no_delivery_options: нет вариантов доставки на этот адрес';
      }

      return $this->formatHttpError($status, $json, 'pricing-calculator');
  }

  private function resolveDestinationPoint(UserAddress $destination): ?array
  {
      $lng = $destination->lng !== null ? (float) $destination->lng : null;
      $lat = $destination->lat !== null ? (float) $destination->lat : null;

      if ($lng === null || $lat === null) {
          $enriched = $this->geocoder->enrichValidatedAddress([
              'country' => 'Россия',
              'city' => (string) $destination->city,
              'line1' => (string) $destination->line1,
              'postal_code' => $destination->postal_code,
          ]);
          $lng = isset($enriched['lng']) ? (float) $enriched['lng'] : null;
          $lat = isset($enriched['lat']) ? (float) $enriched['lat'] : null;
      }

      if ($lng === null || $lat === null) {
          return null;
      }

      $city = trim((string) $destination->city);
      $line1 = trim((string) $destination->line1);

      return [
          'id' => 2,
          'coordinates' => [$lng, $lat],
          'fullname' => $city.', '.$line1,
          'country' => 'Россия',
          'city' => $city,
      ];
  }

  /**
   * @param  array{id: int, coordinates: list<float>, fullname: string, country: string, city: string}  $sender
   * @param  array{id: int, coordinates: list<float>, fullname: string, country: string, city: string}  $dest
   * @return array<string, mixed>
   */
  private function buildExpressCalculateBody(
      array $sender,
      array $dest,
      int $weightG,
      int $lengthCm,
      int $widthCm,
      int $heightCm,
  ): array {
      $weightKg = max(0.001, $weightG / 1000);
      $taxiClasses = ['courier', 'express'];

      return [
          'items' => [[
              'size' => [
                  'length' => max(0.01, $lengthCm / 100),
                  'width' => max(0.01, $widthCm / 100),
                  'height' => max(0.01, $heightCm / 100),
              ],
              'weight' => $weightKg,
              'quantity' => 1,
              'pickup_point' => 1,
              'dropoff_point' => 2,
          ]],
          'route_points' => [$sender, $dest],
          'requirements' => [
              'taxi_classes' => $taxiClasses,
          ],
      ];
  }

  /**
   * @param  array<string, mixed>  $body
   * @return array{ok: bool, message: string, offers_count?: int, cheapest_offer?: array<string, mixed>|null}
   */
  private function postExpressCalculate(string $token, string $env, array $body): array
  {
      $base = YandexDeliveryConfig::expressApiBase($env);

      try {
          $res = Http::withToken($token)
              ->acceptJson()
              ->withHeaders(['Accept-Language' => 'ru'])
              ->timeout(25)
              ->post($base.YandexDeliveryConfig::EXPRESS_OFFERS_CALCULATE_PATH, $body);
      } catch (\Throwable $e) {
          Log::debug('yandex_delivery:express_network', ['message' => $e->getMessage()]);

          return ['ok' => false, 'message' => $e->getMessage()];
      }

      if (! $res->successful()) {
          return ['ok' => false, 'message' => $this->formatHttpError($res->status(), $res->json(), 'offers/calculate')];
      }

      $j = $res->json();
      $offers = is_array($j) ? ($j['offers'] ?? null) : null;
      if (! is_array($offers) || $offers === []) {
          return ['ok' => false, 'message' => 'нет вариантов доставки в ответе'];
      }

      $cheapest = null;
      $cheapestPrice = null;
      foreach ($offers as $offer) {
          if (! is_array($offer)) {
              continue;
          }
          $rub = $this->extractOfferPriceRub($offer);
          if ($rub === null) {
              continue;
          }
          if ($cheapestPrice === null || $rub < $cheapestPrice) {
              $cheapestPrice = $rub;
              $cheapest = $offer;
          }
      }

      if ($cheapest === null) {
          return ['ok' => false, 'message' => 'не удалось прочитать цены вариантов'];
      }

      return ['ok' => true, 'message' => 'OK', 'offers_count' => count($offers), 'cheapest_offer' => $cheapest];
  }

  /**
   * @param  array<string, mixed>  $offer
   */
  private function extractOfferPriceRub(array $offer): ?float
  {
      $price = $offer['price'] ?? null;
      if (! is_array($price)) {
          return null;
      }
      $total = $price['total_price'] ?? $price['total_price_with_vat'] ?? null;
      if ($total === null || $total === '') {
          return null;
      }

      return round((float) $total, 2);
  }

  private function parseRubString(string $raw): ?float
  {
      if ($raw === '') {
          return null;
      }
      if (preg_match('/([\d.,]+)/', $raw, $m)) {
          $n = (float) str_replace(',', '.', $m[1]);

          return round($n, 2);
      }

      return null;
  }

  /**
   * @param  mixed  $json
   */
  private function formatHttpError(int $status, $json, ?string $endpoint = null): string
  {
      $detail = $this->extractApiErrorText($json);
      $msg = 'HTTP '.$status.($detail !== '' ? ': '.$detail : '');

      if ($status === 401) {
          $msg .= '. Токен недействителен или отозван — получите новый в личном кабинете (Интеграции → Получить токен).';
      } elseif ($status === 403) {
          $msg .= '. Доступ запрещён: токен принят, но у договора нет прав на этот метод. '
              .'Экспресс (offers/calculate) требует подключённого продукта «Экспресс-доставка» в ЛК dostavka.yandex.ru. '
              .'«Доставка по России» — отдельный договор и platform_station_id склада. '
              .'Если экспресс не подключён, в «Режимы API» укажите только other_day.';
      } elseif ($status >= 500) {
          $msg .= '. Временная ошибка на стороне Яндекс Доставки — повторите позже.';
      }

      if ($endpoint !== null && $endpoint !== '') {
          $msg = $endpoint.' — '.$msg;
      }

      return $msg;
  }

  /**
   * @param  mixed  $json
   */
  private function extractApiErrorText($json): string
  {
      if (! is_array($json)) {
          return '';
      }

      foreach (['message', 'error', 'description', 'code'] as $key) {
          $v = $json[$key] ?? null;
          if (is_string($v) && trim($v) !== '') {
              return trim($v);
          }
      }

      $errors = $json['errors'] ?? null;
      if (is_array($errors)) {
          $first = $errors[0] ?? null;
          if (is_string($first)) {
              return trim($first);
          }
          if (is_array($first)) {
              $m = $first['message'] ?? $first['text'] ?? null;
              if (is_string($m) && trim($m) !== '') {
                  return trim($m);
              }
          }
      }

      return '';
  }

  /**
   * @param  array<string, mixed>  $config
   */
  private function resolveEnvironment(array $config): string
  {
      return ($config['environment'] ?? YandexDeliveryConfig::ENV_PRODUCTION) === YandexDeliveryConfig::ENV_TEST
          ? YandexDeliveryConfig::ENV_TEST
          : YandexDeliveryConfig::ENV_PRODUCTION;
  }

  /** @return list<float> */
  private function defaultTestDestinationCoords(): array
  {
      return [37.6173, 55.7558];
  }

  private function toFloat(mixed $v): ?float
  {
      if ($v === null || $v === '') {
          return null;
      }
      if (! is_numeric($v)) {
          return null;
      }

      return (float) $v;
  }
}
