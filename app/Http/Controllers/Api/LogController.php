<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ParserLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LogController extends Controller
{
    /**
     * GET /api/v1/logs
     */
    public function index(Request $request): JsonResponse
    {
        $query = ParserLog::query();

        if ($level = $request->input('level')) {
            $query->where('level', $level);
        }
        if ($module = $request->input('module')) {
            $query->where('module', $module);
        }
        if ($jobId = $request->input('job_id')) {
            $query->where('job_id', $jobId);
        }
        if ($search = $request->input('search')) {
            $query->where('message', 'like', "%{$search}%");
        }
        if ($from = $request->input('from')) {
            $query->where('logged_at', '>=', $from);
        }
        if ($to = $request->input('to')) {
            $query->where('logged_at', '<=', $to);
        }

        $perPage = min((int) $request->input('per_page', 50), 200);
        $logs = $query->orderBy('logged_at', 'desc')->paginate($perPage);

        return response()->json([
            'data' => $logs->items(),
            'meta' => [
                'total' => $logs->total(),
                'per_page' => $logs->perPage(),
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
            ],
        ]);
    }

    /**
     * DELETE /api/v1/logs/clear
     * Очистка логов
     */
    public function clear(Request $request): JsonResponse
    {
        $before = $request->input('before'); // дата
        $query = ParserLog::query();
        if ($before) {
            $query->where('logged_at', '<', $before);
        }
        $count = $query->count();
        $query->delete();
        return response()->json(['message' => "Удалено {$count} записей"]);
    }
}
