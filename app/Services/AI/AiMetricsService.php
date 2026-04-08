<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\DB;

class AiMetricsService
{
    /**
     * Stores daily AUTO_APPLIED count in total_predictions column.
     */
    public function recordAutoApplied(float $confidence, string $algorithmVersion): void
    {
        $date = now()->toDateString();

        DB::transaction(function () use ($date, $confidence, $algorithmVersion): void {
            $row = DB::table('ai_metrics')
                ->where('date', $date)
                ->where('algorithm_version', $algorithmVersion)
                ->lockForUpdate()
                ->first();

            if (! $row) {
                DB::table('ai_metrics')->insert([
                    'date' => $date,
                    'algorithm_version' => $algorithmVersion,
                    'total_predictions' => 1,
                    'overrides' => 0,
                    'avg_confidence' => $confidence,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return;
            }

            $total = (int) $row->total_predictions;
            $nextTotal = $total + 1;
            $avg = (float) $row->avg_confidence;
            $nextAvg = (($avg * $total) + $confidence) / $nextTotal;

            DB::table('ai_metrics')->where('id', $row->id)->update([
                'total_predictions' => $nextTotal,
                'avg_confidence' => $nextAvg,
                'updated_at' => now(),
            ]);
        });
    }

    /**
     * Backward-compatible alias.
     */
    public function recordPrediction(float $confidence): void
    {
        $this->recordAutoApplied($confidence, 'v1');
    }

    public function recordManualOverride(string $algorithmVersion): void
    {
        $date = now()->toDateString();

        DB::transaction(function () use ($date, $algorithmVersion): void {
            $row = DB::table('ai_metrics')
                ->where('date', $date)
                ->where('algorithm_version', $algorithmVersion)
                ->lockForUpdate()
                ->first();
            if (! $row) {
                DB::table('ai_metrics')->insert([
                    'date' => $date,
                    'algorithm_version' => $algorithmVersion,
                    'total_predictions' => 0,
                    'overrides' => 1,
                    'avg_confidence' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return;
            }

            DB::table('ai_metrics')->where('id', $row->id)->update([
                'overrides' => (int) $row->overrides + 1,
                'updated_at' => now(),
            ]);
        });
    }

    public function overrideRateForDate(?string $date = null, ?string $algorithmVersion = null): float
    {
        $query = DB::table('ai_metrics')->where('date', $date ?? now()->toDateString());
        if ($algorithmVersion !== null) {
            $query->where('algorithm_version', $algorithmVersion);
        }
        $row = $query->first();
        if (! $row) {
            return 0.0;
        }

        $autoApplied = max(0, (int) $row->total_predictions);
        if ($autoApplied === 0) {
            return 0.0;
        }

        return (float) $row->overrides / (float) $autoApplied;
    }
}

