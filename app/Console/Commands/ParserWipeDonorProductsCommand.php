<?php

namespace App\Console\Commands;

use App\Services\Parser\DonorProductsWipeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ParserWipeDonorProductsCommand extends Command
{
    protected $signature = 'parser:wipe-donor-products
                            {--force : Выполнить без подтверждения (нужно для CI/SSH)}
                            {--keep-jobs : Не очищать parser_jobs и parser_logs}';

    protected $description = 'Удалить все донорские товары парсера (products, фото, атрибуты, похожие, product_sources) и сбросить счётчики; по умолчанию также parser_jobs/parser_logs.';

    public function handle(DonorProductsWipeService $wipe): int
    {
        if (! $this->option('force') && ! $this->confirm(
            'Удалить ВСЕ записи в products и связанных таблицах парсера? Связи product_sources с CRM будут удалены.',
            false
        )) {
            $this->warn('Отменено.');

            return self::SUCCESS;
        }

        $truncateJobs = ! $this->option('keep-jobs');

        $this->info('Очистка донорских данных парсера…');
        $wipe->wipe($truncateJobs);

        $jobsCount = Schema::hasTable('parser_jobs') ? (string) DB::table('parser_jobs')->count() : 'n/a';
        $this->info('Готово. products='.DB::table('products')->count().', parser_jobs='.$jobsCount);
        $this->comment('Рекомендуется: остановить воркеры на время очистки, затем queue:restart и supervisorctl start.');

        return self::SUCCESS;
    }
}
