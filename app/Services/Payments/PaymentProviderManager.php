<?php

namespace App\Services\Payments;

use App\Models\PaymentProvider;
use Illuminate\Support\Facades\Cache;

class PaymentProviderManager
{
    public function getProvider(string $name): PaymentProviderInterface
    {
        $record = $this->getProviderRecord($name);

        if (!$record->is_active) {
            throw new \RuntimeException("Payment provider [{$name}] is not active");
        }

        return $this->makeProviderFromRecord($record, $name);
    }

    /** Экземпляр провайдера из записи БД без проверки is_active (например CRM-возврат по старому платежу). */
    public function getProviderIgnoreActive(string $name): PaymentProviderInterface
    {
        $record = $this->getProviderRecord($name);

        return $this->makeProviderFromRecord($record, $name);
    }

    private function makeProviderFromRecord(PaymentProvider $record, string $name): PaymentProviderInterface
    {
        $config = $record->config ?? [];

        return match (strtolower($name)) {
            'stripe' => new StripeProvider($config),
            'tinkoff' => new TinkoffProvider($config),
            'sber' => new SberProvider($config),
            'atol' => new AtolProvider($config),
            default => throw new \RuntimeException("Unsupported payment provider: {$name}"),
        };
    }

    public function getProviderRecord(string $name): PaymentProvider
    {
        $record = Cache::remember("payment_provider:{$name}", 300, fn () =>
            PaymentProvider::where('name', $name)->first()
        );

        if (!$record) {
            throw new \RuntimeException("Payment provider [{$name}] not found");
        }

        return $record;
    }

    /**
     * Активные провайдеры в стабильном порядке приоритета для витрины:
     * российские эквайринги раньше Stripe (в сидере Stripe часто id=1 и иначе «съедал» дефолт).
     */
    public function getActiveProviderNames(): array
    {
        $active = PaymentProvider::where('is_active', true)
            ->pluck('name')
            ->map(fn ($n) => strtolower((string) $n))
            ->unique()
            ->values()
            ->all();

        if ($active === []) {
            return [];
        }

        $priority = ['tinkoff', 'sber', 'atol', 'stripe'];
        $ordered = [];
        foreach ($priority as $name) {
            if (in_array($name, $active, true)) {
                $ordered[] = $name;
            }
        }
        foreach ($active as $name) {
            if (! in_array($name, $ordered, true)) {
                $ordered[] = $name;
            }
        }

        return $ordered;
    }
}
