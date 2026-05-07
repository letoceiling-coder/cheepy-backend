<?php

namespace App\Services\Marketing;

use App\Models\MarketingEmailTemplate;
use App\Models\User;
use App\Services\MarketplaceSettingsService;
use App\Support\FrontendUrl;

class TransactionalMarketingMail
{
    public function __construct(private MarketplaceSettingsService $settings)
    {
    }

    /**
     * @param  array<string, string>  $extra  Доп. плейсхолдеры {{key}}
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

        $vars = $this->baseVars($user, $extra);
        $subject = $this->merge($tpl->subject, $vars);
        $html = $this->merge($tpl->body_html, $vars);

        return MarketplaceMailDispatcher::sendHtml($email, $subject, $html);
    }

    /**
     * @return array<string, string>
     */
    public function previewVars(?User $user = null, array $extra = []): array
    {
        $u = $user ?? new User(['name' => 'Иван Покупатель', 'email' => 'client@example.com']);
        $demo = [
            'order_number' => 'CH-PREVIEW',
            'order_total' => '4 990 ₽',
        ];

        return $this->baseVars($u, array_merge($demo, $extra));
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

        $base = [
            'customer_name' => $name !== '' ? $name : 'Клиент',
            'marketplace_name' => $marketName,
            'support_email' => $emails[0]['email'] ?? 'support@example.com',
            'support_phone' => $phones[0]['phone'] ?? '',
            'site_url' => FrontendUrl::tryBase() ?? (string) config('app.url', ''),
            'logo_url' => $logoUrl,
            'logo_block' => $logoBlock,
            'recovery_link' => rtrim((string) (FrontendUrl::tryBase() ?? config('app.url', '')), '/').'/cart',
            'order_link' => rtrim((string) (FrontendUrl::tryBase() ?? config('app.url', '')), '/').'/account/orders',
            'promo_summary' => 'Следите за разделом «Акции» на сайте — персональные предложения появятся в кабинете.',
            'products_block' => '<p>В каталоге появились новинки по вашим интересам. Зайдите на сайт, чтобы не пропустить.</p>',
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
