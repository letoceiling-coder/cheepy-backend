<?php

namespace App\Console\Commands;

use App\Models\UserAddress;
use App\Services\Storefront\YandexRuAddressEnrichmentService;
use Illuminate\Console\Command;

/**
 * Одноразово/фоново: подстановка почтовых индексов по Яндекс-геокоду для уже сохранённых адресов РФ.
 */
class DeliveryEnrichUserAddressesCommand extends Command
{
    protected $signature = 'delivery:enrich-addresses
                            {--dry-run : только показать, без записи в БД}
                            {--chunk=80 : размер порции}
                            {--limit=500 : максимум строк за запуск}';

    protected $description = 'Подставить postal_code через YandexRuAddressEnrichment для адресов без индекса (РФ)';

    public function handle(YandexRuAddressEnrichmentService $enrich): int
    {
        $dry = (bool) $this->option('dry-run');
        $chunk = max(1, min(500, (int) $this->option('chunk')));
        $limit = max(1, min(50000, (int) $this->option('limit')));

        $n = 0;
        $updated = 0;

        UserAddress::query()
            ->where(function ($q) {
                $q->whereNull('postal_code')->orWhere('postal_code', '');
            })
            ->where(function ($q) {
                $q->where('country', 'Россия')
                    ->orWhereNull('country')
                    ->orWhere('country', '');
            })
            ->whereNotNull('city')
            ->where('city', '!=', '')
            ->whereNotNull('line1')
            ->where('line1', '!=', '')
            ->orderBy('id')
            ->chunkById($chunk, function ($rows) use ($enrich, $dry, $limit, &$n, &$updated) {
                foreach ($rows as $row) {
                    if ($n >= $limit) {
                        return false;
                    }
                    $n++;
                    /** @var UserAddress $row */
                    $digits = preg_replace('/\D/', '', (string) ($row->postal_code ?? ''));
                    if (strlen($digits) === 6) {
                        continue;
                    }
                    $before = [
                        'city' => (string) $row->city,
                        'line1' => (string) $row->line1,
                        'country' => (string) ($row->country ?? 'Россия'),
                        'region' => $row->region,
                        'postal_code' => $row->postal_code,
                        'lat' => $row->lat,
                        'lng' => $row->lng,
                        'provider_payload' => $row->provider_payload,
                        'source' => (string) ($row->source ?? 'manual'),
                    ];
                    $after = $enrich->enrichValidatedAddress($before);
                    $pd = preg_replace('/\D/', '', (string) ($after['postal_code'] ?? ''));
                    if (strlen($pd) !== 6) {
                        continue;
                    }
                    $updated++;
                    if ($dry) {
                        $this->line("#{$row->id} → postal {$pd} (dry-run)");
                        continue;
                    }
                    $row->update([
                        'postal_code' => $pd,
                        'region' => $after['region'] ?? $row->region,
                        'city' => $after['city'] ?? $row->city,
                        'lat' => $after['lat'] ?? $row->lat,
                        'lng' => $after['lng'] ?? $row->lng,
                        'provider_payload' => $after['provider_payload'] ?? $row->provider_payload,
                        'source' => $after['source'] ?? $row->source,
                    ]);
                }

                return true;
            });

        $this->info("Обработано заявлений порций: строк просмотрено ~ {$n}, с индексом получено: {$updated}".($dry ? ' (dry-run)' : ''));

        return self::SUCCESS;
    }
}
