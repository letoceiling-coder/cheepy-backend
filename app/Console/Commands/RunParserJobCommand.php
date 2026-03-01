<?php

namespace App\Console\Commands;

use App\Models\ParserJob;
use App\Services\DatabaseParserService;
use Illuminate\Console\Command;

class RunParserJobCommand extends Command
{
    protected $signature = 'parser:run {job_id : ID задания из таблицы parser_jobs}';
    protected $description = 'Запустить задание парсера по его ID';

    public function handle(): int
    {
        $jobId = (int) $this->argument('job_id');
        $job = ParserJob::find($jobId);

        if (!$job) {
            $this->error("Задание #{$jobId} не найдено");
            return 1;
        }

        if (!in_array($job->status, ['pending', 'running'])) {
            $this->error("Задание #{$jobId} имеет статус '{$job->status}', запуск невозможен");
            return 1;
        }

        // Сохраняем PID
        $job->update(['pid' => getmypid(), 'started_at' => now()]);

        $this->info("Запуск задания #{$jobId} (type={$job->type})");

        try {
            $service = new DatabaseParserService($job);
            $service->run();

            $job->refresh();
            $this->info("Задание #{$jobId} завершено: {$job->status}");
            $this->info("Сохранено товаров: {$job->saved_products}");
            $this->info("Ошибок: {$job->errors_count}");
        } catch (\Throwable $e) {
            $this->error("Критическая ошибка: " . $e->getMessage());
            $job->update(['status' => 'failed', 'error_message' => $e->getMessage(), 'finished_at' => now()]);
            return 1;
        }

        return 0;
    }
}
