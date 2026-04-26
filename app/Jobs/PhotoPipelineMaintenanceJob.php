<?php

namespace App\Jobs;

use App\Models\ProductPhoto;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PhotoPipelineMaintenanceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 1;

    public function handle(): void
    {
        if (! (bool) config('parser.enable_cleanup', false)) {
            return;
        }

        $failedDays = max(1, (int) config('parser.cleanup_failed_jobs_days', 7));
        $deletedFailed = DB::table('failed_jobs')
            ->where('queue', 'photos')
            ->where('failed_at', '<', now()->subDays($failedDays))
            ->delete();

        $brokenRows = ProductPhoto::query()
            ->whereNotNull('local_path')
            ->where('local_path', '!=', '')
            ->orderBy('id')
            ->limit(1000)
            ->get(['id', 'local_path', 'download_status']);

        $markedBroken = 0;
        foreach ($brokenRows as $row) {
            if (! Storage::disk('local')->exists((string) $row->local_path)) {
                ProductPhoto::query()->where('id', $row->id)->update(['download_status' => 'failed']);
                $markedBroken++;
            }
        }

        $maxScan = max(200, (int) config('parser.cleanup_max_scan_files', 3000));
        $orphanDeleted = 0;
        $scanned = 0;
        $photosRoot = storage_path('app/photos');
        if (is_dir($photosRoot)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($photosRoot, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $file) {
                if (! $file->isFile()) {
                    continue;
                }
                $scanned++;
                $absPath = str_replace('\\', '/', $file->getPathname());
                $relPath = ltrim(str_replace('\\', '/', str_replace(storage_path('app'), '', $absPath)), '/');
                $existsInDb = ProductPhoto::query()
                    ->where('local_path', $relPath)
                    ->orWhere('local_medium_path', $relPath)
                    ->exists();
                if (! $existsInDb) {
                    @unlink($file->getPathname());
                    $orphanDeleted++;
                }
                if ($scanned >= $maxScan) {
                    break;
                }
            }
        }

        $softLimitMb = max(1024, (int) config('parser.storage_soft_limit_mb', 20480));
        $sizeMb = $this->photosStorageSizeMb($photosRoot);
        if ($sizeMb > $softLimitMb) {
            Log::warning('photo-storage soft limit exceeded', [
                'size_mb' => $sizeMb,
                'soft_limit_mb' => $softLimitMb,
            ]);
        }

        Log::info('photo-pipeline-maintenance', [
            'deleted_failed_jobs_photos' => $deletedFailed,
            'broken_records_marked_failed' => $markedBroken,
            'orphan_files_deleted' => $orphanDeleted,
            'scanned_files' => $scanned,
            'photos_storage_mb' => $sizeMb,
        ]);
    }

    private function photosStorageSizeMb(string $photosRoot): int
    {
        if (! is_dir($photosRoot)) {
            return 0;
        }
        $size = 0;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($photosRoot, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if ($file->isFile()) {
                $size += (int) $file->getSize();
            }
        }

        return (int) floor($size / 1024 / 1024);
    }
}

