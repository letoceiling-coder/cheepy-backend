<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    /**
     * GET /api/v1/settings?group=parser
     */
    public function index(Request $request): JsonResponse
    {
        $query = Setting::query();
        if ($group = $request->input('group')) {
            $query->where('group', $group);
        }
        $settings = $query->orderBy('group')->orderBy('key')->get();

        // Сгруппировать по group для удобства
        $grouped = $settings->groupBy('group')->map(fn($items) => $items->keyBy('key')->map(fn($s) => [
            'value' => $this->castValue($s),
            'type' => $s->type,
            'label' => $s->label,
            'description' => $s->description,
        ]));

        return response()->json(['data' => $grouped]);
    }

    /**
     * PUT /api/v1/settings
     * Пакетное обновление настроек
     */
    public function update(Request $request): JsonResponse
    {
        $settings = $request->input('settings', []);
        foreach ($settings as $key => $value) {
            Setting::set($key, $value);
        }
        return response()->json(['message' => 'Настройки сохранены', 'count' => count($settings)]);
    }

    /**
     * PUT /api/v1/settings/{key}
     */
    public function updateOne(Request $request, string $key): JsonResponse
    {
        $value = $request->input('value');
        $group = $request->input('group', 'general');
        Setting::set($key, $value, $group);
        return response()->json(['key' => $key, 'value' => $value]);
    }

    private function castValue(Setting $s): mixed
    {
        return match ($s->type) {
            'int'  => (int) $s->value,
            'bool' => filter_var($s->value, FILTER_VALIDATE_BOOLEAN),
            'json' => json_decode($s->value, true),
            default => $s->value,
        };
    }
}
