<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MarketingEmailTemplate;
use App\Services\Marketing\TransactionalMarketingMail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CrmEmailTemplateController extends Controller
{
    public function index(): JsonResponse
    {
        $rows = MarketingEmailTemplate::query()->orderBy('id')->get();

        return response()->json([
            'data' => $rows->map(fn (MarketingEmailTemplate $t) => [
                'id' => $t->id,
                'slug' => $t->slug,
                'title' => $t->title,
                'send_trigger' => $t->send_trigger,
                'subject' => $t->subject,
                'is_automatic' => $t->is_automatic,
                'is_active' => $t->is_active,
                'placeholder_hint' => $t->placeholder_hint,
                'updated_at' => $t->updated_at?->toIso8601String(),
            ]),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $t = MarketingEmailTemplate::query()->findOrFail($id);

        return response()->json([
            'data' => [
                'id' => $t->id,
                'slug' => $t->slug,
                'title' => $t->title,
                'send_trigger' => $t->send_trigger,
                'subject' => $t->subject,
                'body_html' => $t->body_html,
                'is_automatic' => $t->is_automatic,
                'is_active' => $t->is_active,
                'placeholder_hint' => $t->placeholder_hint,
                'updated_at' => $t->updated_at?->toIso8601String(),
            ],
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $t = MarketingEmailTemplate::query()->findOrFail($id);
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:160'],
            'subject' => ['sometimes', 'string', 'max:255'],
            'body_html' => ['sometimes', 'string'],
            'is_automatic' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'placeholder_hint' => ['sometimes', 'nullable', 'string'],
        ]);
        $t->update($data);
        $t->refresh();

        return response()->json(['data' => $t]);
    }

    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'body_html' => ['required', 'string'],
        ]);
        $mailer = app(TransactionalMarketingMail::class);
        $vars = $mailer->previewVars();

        return response()->json([
            'subject' => $mailer->merge($data['subject'], $vars),
            'body_html' => $mailer->merge($data['body_html'], $vars),
            'variables' => $vars,
        ]);
    }
}
