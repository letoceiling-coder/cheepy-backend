<?php

namespace App\Console\Commands;

use App\Models\DeliveryIntegration;
use App\Services\Storefront\CdekTariffService;
use Illuminate\Console\Command;

/**
 * Диагностика СДЭК только на сервере (боевые ключи и сеть до api.cdek.ru).
 */
class DeliveryDiagnoseCdekCommand extends Command
{
    protected $signature = 'delivery:diagnose-cdek
                            {--city=Анапа : Город получателя, как в user_addresses.city}
                            {--postal= : Индекс получателя (6 цифр), опционально}
                            {--from= : Код города отправителя в СДЭК; иначе CRM sender_city_code / config}';

    protected $description = 'Проверка записи интеграции СДЭК и вызова калькулятора tarifflist (отладка витрины)';

    public function handle(CdekTariffService $cdek): int
    {
        $row = DeliveryIntegration::query()->where('name', 'cdek')->first();
        if ($row === null) {
            $this->error('В БД нет delivery_integrations.name = cdek');

            return self::FAILURE;
        }

        $config = $row->config ?? [];
        $this->line('is_active: '.($row->is_active ? 'true' : 'false'));
        $this->line('client_id задан: '.(! empty($config['client_id']) ? 'да' : 'нет'));
        $this->line('client_secret задан: '.(! empty($config['client_secret']) ? 'да' : 'нет'));
        $this->line('environment: '.(string) ($config['environment'] ?? 'production'));

        $fromOpt = $this->option('from');
        $from = ($fromOpt !== null && $fromOpt !== '') ? (int) $fromOpt : $this->resolveSenderCdekCityCode();
        $this->line('from_city_code (отправитель): '.$from);

        $city = (string) $this->option('city');
        $postalRaw = $this->option('postal');
        $postal = ($postalRaw !== null && $postalRaw !== '') ? (string) $postalRaw : null;
        $this->line('to: city="'.$city.'" postal='.($postal ?? 'null'));
        $this->newLine();

        $res = $cdek->quoteDoorToDoor($from, $city, $postal, 500, 20, 15, 10);

        if (! empty($res['ok']) && isset($res['quote']) && is_array($res['quote'])) {
            $this->info('Калькулятор: OK');
            $this->line(json_encode($res['quote'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->error('Калькулятор: ошибка — '.($res['message'] ?? 'неизвестно'));
        if (! $row->is_active) {
            $this->warn('На витрине quoteDoorToDoor не вызывается при is_active=false (ключи могут быть верны, OAuth в CRM — успешен). Включите интеграцию в CRM.');
        }

        return self::FAILURE;
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
}
