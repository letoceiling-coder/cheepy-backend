<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MarketingCampaign;
use App\Models\User;
use App\Services\Marketing\MarketplaceMailDispatcher;
use App\Services\Marketing\TransactionalMarketingMail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CrmMarketingCampaignController extends Controller
{
    public function index(): JsonResponse
    {
        $rows = MarketingCampaign::query()->orderByDesc('id')->limit(200)->get();

        return response()->json([
            'data' => $rows->map(fn (MarketingCampaign $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'channel' => $c->channel_key,
                'channel_key' => $c->channel_key,
                'audience' => $c->audience,
                'audienceSize' => (int) ($c->metrics['audience_size'] ?? 0),
                'status' => $c->status,
                'sentCount' => (int) ($c->metrics['sent'] ?? 0),
                'openRate' => (float) ($c->metrics['open_rate'] ?? 0),
                'clickRate' => (float) ($c->metrics['click_rate'] ?? 0),
                'scheduledAt' => $c->scheduled_at?->toIso8601String() ?? '—',
                'createdAt' => $c->created_at?->toIso8601String(),
            ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'channel_key' => ['nullable', 'string', 'max:32'],
            'audience' => ['nullable', 'string', 'max:32'],
            'subject' => ['nullable', 'string', 'max:255'],
            'body_html' => ['nullable', 'string'],
            'marketing_email_template_id' => ['nullable', 'integer', 'exists:marketing_email_templates,id'],
        ]);

        $audience = $data['audience'] ?? 'all';
        $size = $this->audienceSize($audience);

        $c = MarketingCampaign::query()->create([
            'name' => $data['name'],
            'channel_key' => $data['channel_key'] ?? 'email',
            'audience' => $audience,
            'status' => 'draft',
            'subject' => $data['subject'] ?? null,
            'body_html' => $data['body_html'] ?? null,
            'marketing_email_template_id' => $data['marketing_email_template_id'] ?? null,
            'metrics' => ['audience_size' => $size],
        ]);

        return response()->json(['data' => $c], 201);
    }

    public function send(Request $request, int $id): JsonResponse
    {
        $campaign = MarketingCampaign::query()->findOrFail($id);
        $payload = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);
        $limit = (int) ($payload['limit'] ?? 200);

        if ($campaign->channel_key !== 'email') {
            return response()->json(['success' => false, 'message' => 'Ручная отправка пока только для email'], 422);
        }

        if (! MarketplaceMailDispatcher::isReady()) {
            return response()->json(['success' => false, 'message' => 'Настройте и включите SMTP в интеграциях'], 422);
        }

        $subject = trim((string) ($campaign->subject ?? ''));
        $html = (string) ($campaign->body_html ?? '');
        if ($subject === '' || trim($html) === '') {
            return response()->json(['success' => false, 'message' => 'Укажите тему и HTML письма у кампании'], 422);
        }

        $users = $this->audienceQuery($campaign->audience)->limit($limit)->get();
        $mailer = app(TransactionalMarketingMail::class);
        $sent = 0;
        foreach ($users as $user) {
            $email = trim((string) ($user->email ?? ''));
            if ($email === '') {
                continue;
            }
            $vars = $mailer->previewVars($user, []);
            $subj = $mailer->merge($subject, $vars);
            $body = $mailer->merge($html, $vars);
            if (MarketplaceMailDispatcher::sendHtml($email, $subj, $body)) {
                $sent++;
            }
        }

        $campaign->update([
            'status' => 'sent',
            'metrics' => array_merge($campaign->metrics ?? [], [
                'sent' => $sent,
                'sent_at' => now()->toIso8601String(),
            ]),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Отправлено писем: '.$sent,
            'sent' => $sent,
        ]);
    }

    private function audienceSize(string $audience): int
    {
        return (int) $this->audienceQuery($audience)->count();
    }

    private function audienceQuery(string $audience)
    {
        $q = User::query()
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->whereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('customer_profiles')
                    ->whereColumn('customer_profiles.user_id', 'users.id')
                    ->where('customer_profiles.marketing_opt_in', true);
            });

        if ($audience === 'new') {
            $q->where('created_at', '>=', now()->subDays(14));
        }

        return $q->orderBy('users.id');
    }
}
