<?php

namespace App\Services\Marketing;

use App\Models\MarketingEmailTemplate;
use App\Models\SystemProduct;
use App\Models\User;
use App\Services\MarketplaceSettingsService;
use App\Support\FrontendUrl;

class TransactionalMarketingMail
{
    public function __construct(
        private MarketplaceSettingsService $settings,
        private MarketingDigestContentService $digest,
    ) {
    }

    /**
     * @param  array<string, string>  $extra  Доп. плейсхолдеры {{key}}, перебивают авто-блоки
     */
    public function trySendTrigger(string $sendTrigger, User $user, array $extra = []): bool
    {
        if (! MarketplaceMailDispatcher::isReady()) {
            return false;
        }

        $email = trim((string) ($user->email ?? ''));
        if ($email === '') {
            return false;
        }

        $tpl = MarketingEmailTemplate::query()
            ->where('send_trigger', $sendTrigger)
            ->where('is_active', true)
            ->where('is_automatic', true)
            ->orderByDesc('id')
            ->first();

        if ($tpl === null) {
            return false;
        }

        $auto = match ($sendTrigger) {
            'promotions', 'preference_new_products' => $this->digestExtrasForTrigger($sendTrigger, $user),
            default => [],
        };

        $vars = $this->baseVars($user, array_merge($auto, $extra));
        $subject = $this->merge($tpl->subject, $vars);
        $html = $this->merge($tpl->body_html, $vars);

        return MarketplaceMailDispatcher::sendHtml($email, $subject, $html);
    }

    /** @return array<string, string> */
    public function previewVars(?User $user = null, array $extra = []): array
    {
        $u = $user ?? new User(['name' => 'Иван Покупатель', 'email' => 'client@example.com']);
        $demo = [
            'order_number' => 'CH-PREVIEW',
            'order_total' => '4 990 ₽',
        ];
        $digest = $this->digest->digestPlaceholderVars();

        /** @var MarketingProductEmailBlockBuilder $blocks */
        $blocks = app(MarketingProductEmailBlockBuilder::class);
        /** @var MarketingInterestProductBlock $interest */
        $interest = app(MarketingInterestProductBlock::class);
        try {
            $sample = SystemProduct::query()
                ->whereIn('status', [SystemProduct::STATUS_APPROVED, SystemProduct::STATUS_PUBLISHED])
                ->with(['photos' => fn ($q) => $q->where('is_enabled', true)->orderBy('sort_order')])
                ->orderByDesc('updated_at')
                ->first();
            $digest['products_block'] = $sample
                ? $blocks->buildFromCheckoutLines([
                    ['product' => $sample, 'quantity' => 2],
                ])
                : '<p style="color:#616187;font-size:14px">Пример блока заказов/корзины: добавьте товары на витрине.</p>';
        } catch (\Throwable) {
            $digest['products_block'] = $blocks->buildFromCheckoutLines([]);
        }
        try {
            $digest['preference_sample_block'] = $interest->htmlBlockForUser($u);
        } catch (\Throwable) {
            $digest['preference_sample_block'] = $digest['products_block'];
        }

        return $this->baseVars($u, array_merge($demo, $digest, $extra));
    }

    /** Переменные для ручной email-кампании из CRM (акции, новости, свежие товары). */
    /** @return array<string, string> */
    public function mergeVarsForCampaign(User $user): array
    {
        $digest = $this->digest->digestPlaceholderVars();
        $digest['products_block'] = app(MarketingInterestProductBlock::class)->htmlBlockForUser($user);

        return $this->baseVars($user, $digest);
    }

    /** @return array<string, string> */
    private function digestExtrasForTrigger(string $sendTrigger, User $user): array
    {
        return match ($sendTrigger) {
            'promotions' => $this->digest->digestPlaceholderVars(),
            'preference_new_products' => array_merge($this->digest->digestPlaceholderVars(), [
                'products_block' => app(MarketingInterestProductBlock::class)->htmlBlockForUser($user),
                'promo_summary' => 'Подборка из каталога по вашим интересам и актуальные акции.',
            ]),
            default => [],
        };
    }

    /**
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    private function baseVars(User $user, array $extra): array
    {
        $all = $this->settings->all();
        $name = trim((string) ($user->name ?? ''));
        /** @var list<array{email:string, description?:string}> $emails */
        $emails = is_array($all['support_emails'] ?? null) ? $all['support_emails'] : [];
        /** @var list<array{phone:string, description?:string}> $phones */
        $phones = is_array($all['support_phones'] ?? null) ? $all['support_phones'] : [];
        $logoUrl = trim((string) ($all['marketplace_logo_url'] ?? ''));
        $marketName = (string) ($all['marketplace_name'] ?? 'Cheepy');
        $logoBlock = '';
        if ($logoUrl !== '') {
            $logoBlock = '<img src="'.htmlspecialchars($logoUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'" alt="'
                .htmlspecialchars($marketName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                .'" style="max-height:48px;display:block;margin-bottom:12px;border:0"/>';
        }
        $logoBlock .= '<div style="font-size:20px;font-weight:700;color:#1f1f2e;">'
            .htmlspecialchars($marketName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</div>';

        $baseUrl = rtrim((string) (FrontendUrl::tryBase() ?? config('app.url', '')), '/');
        $base = [
            'customer_name' => $name !== '' ? $name : 'Клиент',
            'marketplace_name' => $marketName,
            'support_email' => $emails[0]['email'] ?? 'support@example.com',
            'support_phone' => $phones[0]['phone'] ?? '',
            'site_url' => $baseUrl,
            'logo_url' => $logoUrl,
            'logo_block' => $logoBlock,
            'recovery_link' => $baseUrl.'/cart',
            'order_link' => $baseUrl.'/person/orders',
            'promo_summary' => 'Следите за разделом «Акции» на сайте.',
            'products_block' => '<p>В каталоге появились новинки по вашим интересам.</p>',
            'promotions_block' => '',
            'news_block' => '',
            'preference_sample_block' => '',
        ];

        foreach ($extra as $k => $v) {
            $base[(string) $k] = (string) $v;
        }

        return $base;
    }

    /**
     * @param  array<string, string>  $vars
     */
    public function merge(string $text, array $vars): string
    {
        $keys = [];
        $values = [];
        foreach ($vars as $k => $v) {
            $keys[] = '{{'.$k.'}}';
            $values[] = $v;
        }

        return str_replace($keys, $values, $text);
    }
}
