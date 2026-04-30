<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CrmAiAgentChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Единая точка CRM «Агент»: site-al или выбранный в интеграциях LLM.
 *
 * POST /api/v1/admin/site-al/chat
 */
class AdminSiteAlChatController extends Controller
{
    public function chat(Request $request, CrmAiAgentChatService $aiAgentChat): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:50000'],
            'conversationId' => ['nullable', 'string', 'max:256'],
            'agentId' => ['nullable', 'uuid'],
            'model' => ['nullable', 'string', 'max:128'],
        ]);

        return $aiAgentChat->chat($validated);
    }
}
