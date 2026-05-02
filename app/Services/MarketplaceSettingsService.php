<?php

namespace App\Services;

use App\Models\CatalogCategory;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;

class MarketplaceSettingsService
{
    public const GROUP = 'marketplace';

    public function defaults(): array
    {
        return [
            'marketplace_name' => 'Cheepy',
            'support_emails' => [['email' => 'support@cheepy.ru', 'description' => 'Основная поддержка']],
            'support_phones' => [['phone' => '+7 (800) 123-45-67', 'description' => 'Основной телефон']],
            'default_currency' => 'RUB',
            'maintenance_enabled' => false,
            'maintenance_delay_minutes' => 10,
            'maintenance_started_at' => null,
            'seller_registration_enabled' => true,
            'default_commission_percent' => 10,
            'category_commissions' => [],
            'currency_rates' => [
                'date' => null,
                'base' => 'RUB',
                'rates' => [
                    ['code' => 'RUB', 'name' => 'Российский рубль', 'nominal' => 1, 'value' => 1],
                ],
            ],
        ];
    }

    public function all(): array
    {
        $settings = $this->defaults();
        foreach (Setting::query()->where('group', self::GROUP)->get() as $setting) {
            $settings[$setting->key] = $this->cast($setting);
        }

        return $settings;
    }

    public function publicSettings(): array
    {
        $settings = $this->all();
        $this->refreshCurrencyRatesIfStale($settings);
        $settings = $this->all();

        return [
            'marketplace_name' => $settings['marketplace_name'],
            'support_emails' => $settings['support_emails'],
            'support_phones' => $settings['support_phones'],
            'default_currency' => $settings['default_currency'],
            'maintenance' => [
                'enabled' => (bool) $settings['maintenance_enabled'],
                'delay_minutes' => (int) $settings['maintenance_delay_minutes'],
                'started_at' => $settings['maintenance_started_at'],
                'active_at' => $this->maintenanceActiveAt($settings),
            ],
            'seller_registration_enabled' => (bool) $settings['seller_registration_enabled'],
            'currency_rates' => $settings['currency_rates'],
        ];
    }

    public function update(array $data): array
    {
        $current = $this->all();
        $allowed = [
            'marketplace_name',
            'support_emails',
            'support_phones',
            'default_currency',
            'maintenance_enabled',
            'maintenance_delay_minutes',
            'seller_registration_enabled',
            'default_commission_percent',
            'category_commissions',
        ];

        foreach ($allowed as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            $value = $this->normalizeValue($key, $data[$key]);
            if ($key === 'maintenance_enabled') {
                $wasEnabled = (bool) ($current['maintenance_enabled'] ?? false);
                $enabled = (bool) $value;
                $this->set($key, $enabled, 'bool');
                if ($enabled && ! $wasEnabled) {
                    $this->set('maintenance_started_at', now()->toIso8601String(), 'string');
                }
                if (! $enabled) {
                    $this->set('maintenance_started_at', null, 'string');
                }
                continue;
            }

            $this->set($key, $value, $this->typeFor($key));
        }

        return $this->all();
    }

    public function refreshCurrencyRates(): array
    {
        $date = now()->format('d/m/Y');
        $url = 'https://www.cbr.ru/scripts/XML_daily.asp?date_req='.$date;
        $xml = Http::timeout(20)->get($url)->throw()->body();
        $doc = simplexml_load_string($xml);
        if (! $doc) {
            throw new \RuntimeException('CBR XML parse failed');
        }

        $rates = [['code' => 'RUB', 'name' => 'Российский рубль', 'nominal' => 1, 'value' => 1]];
        foreach ($doc->Valute as $valute) {
            $rates[] = [
                'code' => (string) $valute->CharCode,
                'name' => (string) $valute->Name,
                'nominal' => (int) $valute->Nominal,
                'value' => (float) str_replace(',', '.', (string) $valute->Value),
            ];
        }

        $payload = [
            'date' => now()->toDateString(),
            'source' => $url,
            'base' => 'RUB',
            'rates' => $rates,
        ];
        $this->set('currency_rates', $payload, 'json');

        return $payload;
    }

    public function refreshCurrencyRatesIfStale(?array $settings = null): void
    {
        $settings ??= $this->all();
        $date = $settings['currency_rates']['date'] ?? null;
        if ($date !== now()->toDateString()) {
            try {
                $this->refreshCurrencyRates();
            } catch (\Throwable) {
                // Keep the last successful rates; storefront must not fail because CBR is unavailable.
            }
        }
    }

    public function commissionForCategory(?int $categoryId): float
    {
        $settings = $this->all();
        $default = (float) ($settings['default_commission_percent'] ?? 0);
        if (! $categoryId) {
            return $default;
        }

        $map = $settings['category_commissions'] ?? [];
        $category = CatalogCategory::query()->find($categoryId, ['id', 'parent_id']);
        while ($category) {
            $key = (string) $category->id;
            if (array_key_exists($key, $map) && $map[$key] !== null && $map[$key] !== '') {
                return (float) $map[$key];
            }
            $category = $category->parent_id ? CatalogCategory::query()->find($category->parent_id, ['id', 'parent_id']) : null;
        }

        return $default;
    }

    public function priceWithCommission(int|float|null $price, ?int $categoryId): int
    {
        $base = (float) ($price ?? 0);
        if ($base <= 0) {
            return 0;
        }
        $commission = max(0, $this->commissionForCategory($categoryId));
        $withCommission = $base * (1 + $commission / 100);

        return (int) max(1, round($withCommission / 10) * 10);
    }

    private function set(string $key, mixed $value, string $type): void
    {
        Setting::query()->updateOrCreate(
            ['key' => $key],
            ['group' => self::GROUP, 'value' => is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value, 'type' => $type]
        );
    }

    private function cast(Setting $setting): mixed
    {
        return match ($setting->type) {
            'int' => (int) $setting->value,
            'float' => (float) $setting->value,
            'bool' => filter_var($setting->value, FILTER_VALIDATE_BOOLEAN),
            'json' => json_decode((string) $setting->value, true) ?: [],
            default => $setting->value,
        };
    }

    private function typeFor(string $key): string
    {
        return match ($key) {
            'support_emails', 'support_phones', 'category_commissions', 'currency_rates' => 'json',
            'maintenance_enabled', 'seller_registration_enabled' => 'bool',
            'maintenance_delay_minutes' => 'int',
            'default_commission_percent' => 'float',
            default => 'string',
        };
    }

    private function normalizeValue(string $key, mixed $value): mixed
    {
        return match ($key) {
            'support_emails' => collect(is_array($value) ? $value : [])
                ->map(fn ($row) => [
                    'email' => trim((string) ($row['email'] ?? '')),
                    'description' => trim((string) ($row['description'] ?? '')),
                ])
                ->filter(fn ($row) => $row['email'] !== '')
                ->values()
                ->all(),
            'support_phones' => collect(is_array($value) ? $value : [])
                ->map(fn ($row) => [
                    'phone' => trim((string) ($row['phone'] ?? '')),
                    'description' => trim((string) ($row['description'] ?? '')),
                ])
                ->filter(fn ($row) => $row['phone'] !== '')
                ->values()
                ->all(),
            'category_commissions' => collect(is_array($value) ? $value : [])
                ->mapWithKeys(fn ($v, $k) => [(string) $k => max(0, (float) $v)])
                ->all(),
            'maintenance_delay_minutes' => max(1, min(1440, (int) $value)),
            'default_commission_percent' => max(0, (float) $value),
            default => $value,
        };
    }

    private function maintenanceActiveAt(array $settings): ?string
    {
        if (! ($settings['maintenance_enabled'] ?? false) || empty($settings['maintenance_started_at'])) {
            return null;
        }

        return \Carbon\Carbon::parse($settings['maintenance_started_at'])
            ->addMinutes((int) ($settings['maintenance_delay_minutes'] ?? 10))
            ->toIso8601String();
    }
}
