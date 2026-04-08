<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AiMetricsController extends Controller
{
    public function index(): JsonResponse
    {
        $rows = DB::table('ai_metrics')
            ->select(['algorithm_version', 'total_predictions', 'overrides', 'avg_confidence'])
            ->orderBy('algorithm_version')
            ->get();

        $data = $rows->map(function ($row): array {
            $totalPredictions = (int) $row->total_predictions;
            $overrides = (int) $row->overrides;
            $overrideRate = $totalPredictions > 0 ? $overrides / $totalPredictions : 0.0;

            return [
                'algorithm_version' => (string) $row->algorithm_version,
                'total_predictions' => $totalPredictions,
                'overrides' => $overrides,
                'avg_confidence' => (float) $row->avg_confidence,
                'override_rate' => $overrideRate,
            ];
        })->values();

        return response()->json([
            'data' => $data,
        ]);
    }
}

