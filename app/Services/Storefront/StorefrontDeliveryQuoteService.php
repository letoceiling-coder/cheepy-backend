<?php

namespace App\Services\Storefront;

use App\Models\DeliveryIntegration;
use App\Models\SystemProduct;
use App\Models\UserAddress;
use Carbon\Carbon;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Log;

/**
 * Оркестратор расчёта доставки для карточки товара.
 *
 * Отдаёт массив вариантов по активным интеграциям (СДЭК, Почта России)
 * при наличии у пользователя адреса с достаточными данными.
 */
class StorefrontDeliveryQuoteService
{
    public function __construct(
        private readonly CdekTariffService $cdek,
        private readonly RussianPostTariffService $pochta,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function buildQuotesForProduct(Authenticatable $user, SystemProduct $product, int $quantity): array
    {
        $quantity = max(1, min(99, $quantity));

        $address = UserAddress::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();

        if ($address === null) {
            return [
                'needs_address' => true,
                'message' => 'Добавьте адрес доставки в личном кабинете, чтобы видеть расчёт.',
                'address' => null,
                'shipment' => $this->shipmentSlice($product, $quantity),
                'quotes' => [],
                'warnings' => [],
            ];
        }

        $address = $this->persistPostalFromGeocodeWhenMissing($address);

        [$weightG, $l, $w, $h] = $this->packageDimensions($product, $quantity);
        $shipment = $this->shipmentSlice($product, $quantity);

        $fromCdek = $this->resolveSenderCdekCityCode();

        /** @var list<array<string, mixed>> $quotes */
        $quotes = [];

        $cdekRes = $this->cdek->quoteDoorToDoor(
            $fromCdek,
            (string) $address->city,
            $address->postal_code,
            $weightG,
            $l,
            $w,
            $h,
        );

        if (! empty($cdekRes['ok']) && isset($cdekRes['quote']) && is_array($cdekRes['quote'])) {
            $quotes[] = $this->enrichPresentation($cdekRes['quote'], 'Курьерская доставка');
        } elseif (($cdekRes['message'] ?? '') !== '') {
            Log::debug('storefront_delivery_quote:cdek_failed', [
                'user_id' => $user->getAuthIdentifier(),
                'system_product_id' => $product->id ?? null,
                'message' => $cdekRes['message'],
            ]);
        }

        $originIndex = preg_replace('/\D/', '', (string) config('delivery.origin.postal_index', '101000'));
        $destinationIndex = $address->postal_code !== null ? preg_replace('/\D/', '', (string) $address->postal_code) : '';
        $declaredKop = null;
        if ($product->price_raw !== null && (int) $product->price_raw > 0) {
            $declaredKop = (int) $product->price_raw * 100 * $quantity;
        }

        if (strlen($destinationIndex) === 6) {
            $rp = $this->pochta->quote($originIndex, $destinationIndex, $weightG, $declaredKop);
            if (! empty($rp['ok']) && isset($rp['quote']) && is_array($rp['quote'])) {
                $quotes[] = $this->enrichPresentation($rp['quote'], 'Курьерская доставка');
            } elseif (($rp['message'] ?? '') !== '') {
                Log::debug('storefront_delivery_quote:russian_post_failed', [
                    'user_id' => $user->getAuthIdentifier(),
                    'system_product_id' => $product->id ?? null,
                    'message' => $rp['message'],
                ]);
            }
        }

        return [
            'needs_address' => false,
            'address' => $this->addressSlice($address),
            'shipment' => $shipment,
            'quotes' => $quotes,
            'warnings' => [],
        ];
    }

    private function enrichPresentation(array $quote, string $serviceLabel): array
    {
        $pMin = (int) ($quote['period_min_days'] ?? 0);
        $pMax = (int) ($quote['period_max_days'] ?? $pMin);
        $fmt = $this->formatEstimatedWindow($pMin, $pMax);
        $price = (float) ($quote['price_rub'] ?? 0);

        $priceStr = number_format($price, 2, '.', ' ');
        $summaryDate = $fmt['iso_from'] === $fmt['iso_to']
            ? $fmt['ru_from']
            : sprintf('с %s по %s', $fmt['ru_from'], $fmt['ru_to']);

        return array_merge($quote, [
            'display_service_label' => $serviceLabel,
            'date_from' => $fmt['iso_from'],
            'date_to' => $fmt['iso_to'],
            'date_from_label_ru' => $fmt['ru_from'],
            'date_to_label_ru' => $fmt['ru_to'],
            'summary_line_ru' => sprintf(
                '%s: %s ₽, %s',
                $serviceLabel,
                $priceStr,
                $summaryDate
            ),
        ]);
    }

    /**
     * @return array{iso_from: string, iso_to: string, ru_from: string, ru_to: string}
     */
    private function formatEstimatedWindow(int $periodMinDays, int $periodMaxDays): array
    {
        $periodMinDays = max(0, $periodMinDays);
        $periodMaxDays = max($periodMinDays, $periodMaxDays);
        Carbon::setLocale('ru');

        $from = Carbon::now()->startOfDay()->addDays($periodMinDays);
        $to = Carbon::now()->startOfDay()->addDays($periodMaxDays);

        return [
            'iso_from' => $from->toDateString(),
            'iso_to' => $to->toDateString(),
            'ru_from' => $from->translatedFormat('j F'),
            'ru_to' => $to->translatedFormat('j F'),
        ];
    }

    /**
     * @return array{weight_g: int, length_cm: int, width_cm: int, height_cm: int, declared_value_kopeks: ?int, quantity: int}
     */
    private function shipmentSlice(SystemProduct $product, int $quantity): array
    {
        [$weightG, $l, $w, $h] = $this->packageDimensions($product, $quantity);

        return [
            'quantity' => $quantity,
            'weight_g' => $weightG,
            'length_cm' => $l,
            'width_cm' => $w,
            'height_cm' => $h,
            'declared_value_kopeks' => $product->price_raw !== null && (int) $product->price_raw > 0
                ? (int) $product->price_raw * 100 * $quantity
                : null,
        ];
    }

    /**
     * @return array{id: int, label: ?string, city: string, line1: string, postal_code: ?string}
     */
    private function addressSlice(UserAddress $a): array
    {
        return [
            'id' => $a->id,
            'label' => $a->label,
            'city' => $a->city,
            'line1' => $a->line1,
            'postal_code' => $a->postal_code,
        ];
    }

    /**
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    private function packageDimensions(SystemProduct $product, int $quantity): array
    {
        $defs = config('delivery.package_defaults', []);

        $baseW = max(1, (int) ($product->shipping_weight_g ?? ($defs['weight_g'] ?? 500)));
        $lg = max(1, (int) ($product->shipping_length_cm ?? ($defs['length_cm'] ?? 20)));
        $wg = max(1, (int) ($product->shipping_width_cm ?? ($defs['width_cm'] ?? 15)));
        $hg = max(1, (int) ($product->shipping_height_cm ?? ($defs['height_cm'] ?? 10)));

        $weightTotal = max(1, $baseW * $quantity);

        return [$weightTotal, $lg, $wg, $hg];
    }

    private function resolveSenderCdekCityCode(): int
    {
        $fallback = (int) config('delivery.origin.cdek_city_code', 44);
        $row = DeliveryIntegration::query()->where('name', 'cdek')->first();
        $c = trim((string) (($row->config ?? [])['sender_city_code'] ?? ''));
        if ($c !== '' && ctype_digit($c)) {
            return (int) $c;
        }

        return $fallback > 0 ? $fallback : 44;
    }

    /**
     * Если индекс пустой — один раз подставляем через Яндекс (кэш сервиса), сохраняем в user_addresses.
     */
    private function persistPostalFromGeocodeWhenMissing(UserAddress $address): UserAddress
    {
        $digits = preg_replace('/\D/', '', (string) ($address->postal_code ?? ''));
        if (strlen($digits) === 6) {
            return $address;
        }

        $enrich = app(YandexRuAddressEnrichmentService::class);
        $payload = $enrich->enrichValidatedAddress([
            'country' => $address->country,
            'region' => $address->region,
            'city' => (string) $address->city,
            'line1' => (string) $address->line1,
            'line2' => $address->line2,
            'postal_code' => $address->postal_code,
            'lat' => $address->lat,
            'lng' => $address->lng,
            'provider_payload' => is_array($address->provider_payload) ? $address->provider_payload : null,
            'source' => (string) ($address->source ?? 'manual'),
        ]);

        $newPc = preg_replace('/\D/', '', (string) ($payload['postal_code'] ?? ''));
        if (strlen($newPc) !== 6) {
            return $address;
        }

        $address->update([
            'postal_code' => $newPc,
            'region' => $payload['region'] ?? $address->region,
            'city' => $payload['city'] ?? $address->city,
            'lat' => $payload['lat'] ?? $address->lat,
            'lng' => $payload['lng'] ?? $address->lng,
            'provider_payload' => $payload['provider_payload'] ?? $address->provider_payload,
            'source' => $payload['source'] ?? $address->source,
        ]);

        return $address->refresh();
    }
}
