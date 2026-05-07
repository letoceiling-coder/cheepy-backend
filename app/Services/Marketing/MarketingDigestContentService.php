<?php

namespace App\Services\Marketing;

use App\Models\CmsPage;
use App\Models\Coupon;
use App\Models\MarketingNews;
use App\Models\SystemProduct;
use App\Support\FrontendUrl;

class MarketingDigestContentService
{
    /**
     * HTML: активные купоны/акции из CRM.
     */
    public function activePromotionsHtml(int $limit = 12): string
    {
        $now = now();
        $rows = Coupon::query()
            ->where('is_active', true)
            ->where(function ($q) use ($now) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>=', $now);
            })
            ->where(function ($q) {
                $q->whereNull('max_uses')->orWhereColumn('used_count', '<', 'max_uses');
            })
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['code', 'name', 'description', 'discount_type', 'discount_value', 'expires_at']);

        if ($rows->isEmpty()) {
            return '<p style="margin:0;color:#616187;font-size:14px">Сейчас нет активных промокодов — загляните на витрину позже.</p>';
        }

        $base = rtrim((string) (FrontendUrl::tryBase() ?? config('app.url', '')), '/');
        $lis = [];
        foreach ($rows as $c) {
            $title = htmlspecialchars((string) ($c->name ?: $c->code), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $code = htmlspecialchars((string) $c->code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $desc = trim((string) ($c->description ?? ''));
            $descHtml = $desc !== ''
                ? '<br/><span style="color:#616187;font-size:13px">'.htmlspecialchars($desc, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</span>'
                : '';
            $expires = $c->expires_at ? ' до '.$c->expires_at->format('d.m.Y') : '';
            $discount = '';
            if ($c->discount_type === 'percent') {
                $discount = ' −'.(float) $c->discount_value.'%';
            } elseif ($c->discount_type === 'fixed') {
                $discount = ' −'.number_format((float) $c->discount_value, 0, ',', ' ').' ₽';
            }
            $lis[] =
                '<tr><td style="padding:12px 0;border-bottom:1px solid #eee">'
                .'<strong style="font-size:15px">'.$title.'</strong>'
                .$descHtml
                .'<div style="margin-top:6px;font-size:13px">Код: <strong style="font-family:monospace">'.$code.'</strong>'
                .$discount.(htmlspecialchars($expires, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')).'</div>'
                .'<div style="margin-top:10px"><a href="'.htmlspecialchars($base.'/account/coupons', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                .'" style="color:#5b53e8;font-weight:600">Применить в кабинете</a></div>'
                .'</td></tr>';
        }

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:16px 0">'
            .implode('', $lis).'</table>';
    }

    /**
     * HTML: последние опубликованные CMS-страницы (новости/лендинги).
     */
    public function recentNewsPagesHtml(int $limit = 8): string
    {
        $base = rtrim((string) (FrontendUrl::tryBase() ?? config('app.url', '')), '/');

        $pages = CmsPage::query()
            ->where('is_active', true)
            ->where('status', CmsPage::STATUS_PUBLISHED)
            ->whereNotNull('published_version_id')
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get(['title', 'path_prefix', 'slug']);

        if ($pages->isEmpty()) {
            return '<p style="margin:0;color:#616187;font-size:14px">Новостей пока нет — следите за обновлениями на сайте.</p>';
        }

        $lis = [];
        foreach ($pages as $p) {
            $slug = strtolower(trim((string) $p->slug, '/'));
            $pref = strtolower(trim((string) $p->path_prefix, '/'));
            $url = htmlspecialchars($base.'/'.$pref.'/'.$slug, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $title = htmlspecialchars((string) $p->title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $lis[] = '<li style="margin:8px 0"><a href="'.$url.'" style="color:#5b53e8;font-weight:600">'.$title.'</a></li>';
        }

        return '<div style="margin:14px 0"><p style="font-weight:700;margin-bottom:10px;color:#24243a">Свежие материалы</p>'
            .'<ul style="margin:0;padding-left:20px">'.implode('', $lis).'</ul></div>';
    }

    /**
     * Новости из CRM (раздел Маркетинг → Новости): активные и с датой публикации не в будущем.
     */
    public function crmMarketingNewsHtml(int $limit = 12): string
    {
        $now = now();
        $rows = MarketingNews::query()
            ->where('is_active', true)
            ->where(function ($q) use ($now) {
                $q->whereNull('published_at')->orWhere('published_at', '<=', $now);
            })
            ->orderByDesc('sort_order')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['title', 'body', 'slug', 'image_url', 'video_url', 'file_url', 'file_label']);

        if ($rows->isEmpty()) {
            return '';
        }

        $base = rtrim((string) (FrontendUrl::tryBase() ?? config('app.url', '')), '/');

        $chunks = [];
        foreach ($rows as $n) {
            $title = htmlspecialchars((string) $n->title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $plain = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $n->body)));
            $excerpt = htmlspecialchars(mb_substr($plain, 0, 220).(mb_strlen($plain) > 220 ? '…' : ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $img = trim((string) ($n->image_url ?? ''));
            $imgHtml = $img !== ''
                ? '<div style="margin:8px 0"><img src="'.htmlspecialchars($img, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'" alt="" style="max-width:100%;border-radius:12px;border:0"/></div>'
                : '';
            $links = '';
            $video = trim((string) ($n->video_url ?? ''));
            if ($video !== '') {
                $links .= '<div style="margin:6px 0"><a href="'.htmlspecialchars($video, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'" style="color:#5b53e8">Видео</a></div>';
            }
            $fileUrl = trim((string) ($n->file_url ?? ''));
            if ($fileUrl !== '') {
                $label = htmlspecialchars((string) ($n->file_label ?: 'Файл'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $links .= '<div style="margin:6px 0"><a href="'.htmlspecialchars($fileUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'" style="color:#5b53e8">'.$label.'</a></div>';
            }
            $chunks[] =
                '<div style="margin:14px 0;padding:14px;background:#faf8ff;border-radius:12px;border:1px solid #ece9ff">'
                .'<p style="margin:0 0 8px;font-size:17px;font-weight:700">'.$title.'</p>'
                .$imgHtml
                .'<p style="margin:0;line-height:1.45;color:#3d3d52">'.$excerpt.'</p>'
                .$links.'</div>';
        }

        return '<div style="margin:12px 0"><p style="font-weight:700;margin-bottom:6px;color:#24243a">Новости маркетплейса</p>'
            .implode('', $chunks).'</div>';
    }

    /** CMS + CRM новости в одном блоке для писем. */
    public function combinedNewsHtml(): string
    {
        $crm = $this->crmMarketingNewsHtml();
        $cms = $this->recentNewsPagesHtml(6);
        if ($crm === '') {
            return $cms;
        }
        if (str_contains($cms, 'Новостей пока нет')) {
            return $crm;
        }

        return $crm.'<p style="margin:16px 0 8px;font-weight:700;color:#24243a">Страницы на сайте</p>'.$cms;
    }

    /** @return array<string, string> */
    public function digestPlaceholderVars(): array
    {
        return [
            'promotions_block' => $this->activePromotionsHtml(),
            'news_block' => $this->combinedNewsHtml(),
            'promo_summary' => 'Активные промокоды и новости по состоянию на момент отправки.',
        ];
    }

    /** HTML блок свежих товаров (витринные статусы). */
    public function newestListedProductsHtml(int $limit = 8): string
    {
        $products = SystemProduct::query()
            ->whereIn('status', [SystemProduct::STATUS_APPROVED, SystemProduct::STATUS_PUBLISHED])
            ->with(['photos' => fn ($q) => $q->where('is_enabled', true)->orderBy('sort_order')])
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        $lines = [];
        foreach ($products as $p) {
            $lines[] = ['product' => $p, 'quantity' => 1];
        }

        return app(MarketingProductEmailBlockBuilder::class)->buildFromCheckoutLines($lines);
    }
}
