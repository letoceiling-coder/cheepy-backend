<?php

namespace App\Services\Marketing;

use App\Models\CustomerOrder;
use App\Models\SystemProduct;
use App\Services\Catalog\PublicSystemCatalogService;
use App\Support\FrontendUrl;
use Illuminate\Support\Facades\Log;

/**
 * HTML таблицы товаров для писем — с проверкой видимости в каталоге.
 */
class MarketingProductEmailBlockBuilder
{
    /**
     * @param  iterable<int, array{product:SystemProduct, quantity:int, unavailable?:bool, note?: string}>  $resolved
     */
    public function buildFromProducts(iterable $resolved): string
    {
        $rows = '';
        foreach ($resolved as $row) {
            /** @var SystemProduct $p */
            $p = $row['product'];
            $qty = max(1, (int) $row['quantity']);
            $unavailable = ! empty($row['unavailable']);
            /** @var PublicSystemCatalogService $catalog */
            $catalog = app(PublicSystemCatalogService::class);
            $thumb = htmlspecialchars((string) ($p->photos->first()?->url ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $name = htmlspecialchars((string) $p->name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            try {
                $priceInt = $catalog->priceForStorefront($p);
                $price = $priceInt > 0 ? number_format($priceInt, 0, ',', ' ').' ₽' : htmlspecialchars((string) $p->price, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            } catch (\Throwable) {
                $price = '—';
            }
            $base = rtrim((string) (FrontendUrl::tryBase() ?? config('app.url', '')), '/');
            $pid = htmlspecialchars($catalog->storefrontPublicProductId($p), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $url = htmlspecialchars($base.'/product/'.$pid, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            $visibleOnStorefront = in_array((string) $p->status, [
                SystemProduct::STATUS_APPROVED,
                SystemProduct::STATUS_PUBLISHED,
            ], true);

            $status = '';
            if ($unavailable || ! $visibleOnStorefront) {
                $status = '<span style="color:#b42318;font-size:12px;font-weight:600">Недоступен для заказа</span>';
            }

            $img = $thumb !== ''
                ? '<img src="'.$thumb.'" width="72" height="72" alt="" style="display:block;width:72px;height:72px;object-fit:cover;border-radius:8px;border:0"/>'
                : '<div style="width:72px;height:72px;background:#eef0fb;border-radius:8px;"></div>';

            $rows .= '<tr>'
                .'<td style="vertical-align:middle;width:82px">'.$img.'</td>'
                .'<td style="vertical-align:middle;padding-left:14px;line-height:1.4">'
                .'<div><a href="'.$url.'" style="color:#24243a;font-weight:700;text-decoration:none">'.$name.'</a></div>'
                .'<div style="margin-top:6px;color:#616187;font-size:14px">'.$qty.' × '.$price.'</div>'
                .$status.'</td>'
                .'</tr>';
        }

        if ($rows === '') {
            return '<p style="color:#616187;margin:12px 0">Список товаров пока пуст.</p>';
        }

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:separate;border-spacing:0 12px">'.$rows.'</table>';
    }

    /**
     * @param  array<int, array<string, mixed>>  $snapshotItems [['product_id' => string, 'quantity' => int, ...], ...]
     * @return array{0:string,available:int,total:int}|null null если ни одной валидной позиции
     */
    public function buildFromSnapshotItems(array $snapshotItems): ?array
    {
        $catalog = app(PublicSystemCatalogService::class);
        $resolved = [];
        foreach ($snapshotItems as $line) {
            $pid = trim((string) ($line['product_id'] ?? ''));
            if ($pid === '') {
                continue;
            }
            $qty = max(1, (int) ($line['quantity'] ?? 1));
            try {
                $p = $catalog->findVisibleSystemProductByPublicId($pid);
                $p->loadMissing('photos');
                $resolved[] = ['product' => $p, 'quantity' => $qty, 'unavailable' => false];
            } catch (\Throwable $e) {
                Log::debug('marketing_cart_line_unavailable', ['product_id' => $pid, 'message' => $e->getMessage()]);
                $p = null;
                $qBase = SystemProduct::query()->with('photos');
                if (str_starts_with($pid, 'sp-')) {
                    $sid = (int) substr($pid, 3);
                    if ($sid > 0) {
                        $p = (clone $qBase)->whereKey($sid)->first();
                    }
                } elseif (ctype_digit($pid)) {
                    $p = (clone $qBase)->whereKey((int) $pid)->first();
                }
                if ($p !== null) {
                    $resolved[] = ['product' => $p, 'quantity' => $qty, 'unavailable' => true];
                }
            }
        }

        $availableLines = array_filter($resolved, fn ($r) => empty($r['unavailable']));
        if ($availableLines === []) {
            return null;
        }

        $html = $this->buildFromProducts($resolved);

        return [$html, count($availableLines), count($resolved)];
    }

    /**
     * @param  iterable<int, array{product:SystemProduct, quantity:int}>  $checkoutLines  как в checkout
     */
    public function buildFromCheckoutLines(iterable $checkoutLines): string
    {
        $resolved = [];
        foreach ($checkoutLines as $line) {
            $p = $line['product'];
            if ($p instanceof SystemProduct) {
                $p->loadMissing('photos');
                $resolved[] = ['product' => $p, 'quantity' => max(1, (int) $line['quantity']), 'unavailable' => false];
            }
        }

        return $this->buildFromProducts($resolved);
    }

    /**
     * Позиции заказа с повторной проверкой видимости в каталоге на момент отправки письма.
     */
    public function buildFromCustomerOrder(CustomerOrder $order): string
    {
        $order->loadMissing([
            'items',
        ]);
        /** @var PublicSystemCatalogService $catalog */
        $catalog = app(PublicSystemCatalogService::class);

        $resolved = [];
        foreach ($order->items as $item) {
            $pid = (int) $item->product_id;
            if ($pid <= 0) {
                continue;
            }
            $p = SystemProduct::query()
                ->with(['photos' => fn ($q) => $q->where('is_enabled', true)->orderBy('sort_order')])
                ->find($pid);
            if ($p === null) {
                continue;
            }
            $publicId = $catalog->storefrontPublicProductId($p);
            $qty = max(1, (int) $item->quantity);
            try {
                $fresh = $catalog->findVisibleSystemProductByPublicId($publicId);
                $fresh->loadMissing(['photos' => fn ($q) => $q->where('is_enabled', true)->orderBy('sort_order')]);
                $resolved[] = ['product' => $fresh, 'quantity' => $qty, 'unavailable' => false];
            } catch (\Throwable) {
                $resolved[] = ['product' => $p, 'quantity' => $qty, 'unavailable' => true];
            }
        }

        return $this->buildFromProducts($resolved);
    }
}
