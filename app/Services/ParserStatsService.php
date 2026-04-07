<?php

namespace App\Services;

use App\Models\ParserJob;
use Illuminate\Support\Facades\DB;

class ParserStatsService
{
    public function recordJob(ParserJob $job): void
    {
        $date = $job->finished_at?->toDateString() ?? now()->toDateString();
        $saved = (int) ($job->progress['saved'] ?? 0);
        $errors = (int) ($job->progress['errors'] ?? 0);
        $cats = (int) (($job->progress['categories']['done'] ?? 0) ?: 1);
        $duration = $this->jobDurationSeconds($job);

        $row = DB::table('parser_stats')->where('date', $date)->first();
        if ($row) {
            DB::table('parser_stats')->where('date', $date)->update([
                'products_parsed' => $row->products_parsed + $saved,
                'errors_count' => $row->errors_count + $errors,
                'duration_seconds' => $row->duration_seconds + $duration,
                'categories_parsed' => $row->categories_parsed + $cats,
                'jobs_count' => $row->jobs_count + 1,
                'updated_at' => now(),
            ]);
        } else {
            DB::table('parser_stats')->insert([
                'date' => $date,
                'products_parsed' => $saved,
                'errors_count' => $errors,
                'duration_seconds' => $duration,
                'categories_parsed' => $cats,
                'jobs_count' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function getStats(): array
    {
        return DB::table('parser_stats')
            ->selectRaw('SUM(products_parsed) as products_parsed, SUM(errors_count) as errors_count, SUM(duration_seconds) as duration_seconds, SUM(categories_parsed) as categories_parsed, SUM(jobs_count) as jobs_count')
            ->first();
    }

    public function getDaily(int $days = 30): array
    {
        return DB::table('parser_stats')
            ->where('date', '>=', now()->subDays($days)->toDateString())
            ->orderByDesc('date')
            ->get()
            ->toArray();
    }

    public function getErrorsDaily(int $days = 30): array
    {
        return DB::table('parser_stats')
            ->where('date', '>=', now()->subDays($days)->toDateString())
            ->where('errors_count', '>', 0)
            ->orderByDesc('date')
            ->get(['date', 'errors_count', 'products_parsed'])
            ->toArray();
    }

    private function jobDurationSeconds(ParserJob $job): int
    {
        $start = $job->started_at ?? $job->created_at;
        $end = $job->finished_at ?? now();
        return max(0, $end->diffInSeconds($start));
    }
}
