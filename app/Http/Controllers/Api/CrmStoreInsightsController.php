<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\PaymentWebhookLog;
use App\Models\Seller;
use App\Models\SellerReview;
use App\Models\SystemProduct;
use App\Models\User;
use App\Services\MarketplaceSettingsService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CrmStoreInsightsController extends Controller
{
    public function __construct(
        private MarketplaceSettingsService $marketplaceSettings,
    ) {}

    /** GET /crm/store-insights/overview */
    public function overview(Request $request): JsonResponse
    {
        [$start, $end, $days] = $this->resolvePeriod($request);
        [$prevStart] = $this->previousWindow($start, $end);

        $kpis = [
            'revenue_rub' => $this->paidRevenueRub($start, $end),
            'revenue_prev_rub' => $this->paidRevenueRub($prevStart, $start->copy()->subSecond()),
            'orders_count' => $this->paidOrdersCount($start, $end),
            'orders_prev' => $this->paidOrdersCount($prevStart, $start->copy()->subSecond()),
            'users_registered' => User::query()->count(),
            'users_new_in_period' => User::query()->whereBetween('created_at', [$start, $end])->count(),
            'sellers_active' => Seller::query()->where('status', 'active')->count(),
            'sellers_total' => Seller::query()->count(),
            'avg_check_rub' => 0,
            'top_catalog_category' => $this->topCatalogCategoryName($start, $end),
        ];

        $kpis['avg_check_rub'] = $kpis['orders_count'] > 0
            ? (int) round($kpis['revenue_rub'] / $kpis['orders_count'])
            : 0;

        return response()->json([
            'data' => [
                'period' => [
                    'days' => $days,
                    'start' => $start->toIso8601String(),
                    'end' => $end->toIso8601String(),
                ],
                'kpis' => $kpis,
                'sales_chart' => $this->salesChartRows($start, $end, $days),
                'recent_orders' => $this->recentPaidOrders(5),
                'top_products' => $this->topProducts($start, $end, 8),
            ],
        ]);
    }

    /** GET /crm/store-insights/analytics */
    public function analytics(Request $request): JsonResponse
    {
        [$start, $end, $days] = $this->resolvePeriod($request);

        return response()->json([
            'data' => [
                'period' => [
                    'days' => $days,
                    'start' => $start->toIso8601String(),
                    'end' => $end->toIso8601String(),
                ],
                'sales_chart' => $this->salesChartRows($start, $end, $days),
                'category_revenue' => $this->categoryRevenue($start, $end),
                'geo_delivery' => $this->geoDelivery($start, $end),
                'order_status_funnel' => $this->orderStatusFunnel($start, $end),
                'payment_summary' => $this->paymentCounts(),
            ],
        ]);
    }

    /** GET /crm/store-users */
    public function storeUsers(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', 50)));
        $pageNum = max(1, (int) $request->query('page', 1));
        $search = trim((string) $request->query('search', ''));
        $role = trim((string) $request->query('role', 'all'));

        $q = User::query()->orderByDesc('id');

        if ($search !== '') {
            $like = '%'.$search.'%';
            $q->where(function ($qq) use ($like) {
                $qq->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('phone', 'like', $like);
            });
        }

        if ($role !== '' && $role !== 'all') {
            if ($role === 'seller') {
                $q->where(function ($qq) {
                    $qq->where('account_role', 'seller')->orWhereNotNull('seller_status');
                });
            } elseif ($role === 'customer') {
                $q->where('account_role', 'customer')->whereNull('seller_status');
            } elseif (in_array($role, ['moderator', 'admin'], true)) {
                $q->whereRaw('1 = 0');
            }
        }

        $walletSums = DB::table('customer_wallet_ledger')
            ->selectRaw('user_id, SUM(amount) as balance')
            ->groupBy('user_id');

        $orderAgg = DB::table('customer_orders')
            ->select([
                'user_id',
                DB::raw('COUNT(*) as orders_total'),
                DB::raw("SUM(CASE WHEN payment_status = 'paid' THEN total_amount ELSE 0 END) as spent_paid"),
            ])
            ->groupBy('user_id');

        /** @phpstan-ignore-next-line dynamic call */
        $page = $q
            ->leftJoinSub($walletSums, 'w', 'w.user_id', '=', 'users.id')
            ->leftJoinSub($orderAgg, 'o', 'o.user_id', '=', 'users.id')
            ->select([
                'users.*',
                DB::raw('COALESCE(w.balance, 0) as wallet_balance'),
                DB::raw('COALESCE(o.orders_total, 0) as orders_count'),
                DB::raw('COALESCE(o.spent_paid, 0) as total_spent'),
            ])
            ->paginate($perPage, ['*'], 'page', $pageNum);

        $data = collect($page->items())->map(fn ($row) => $this->serializeStoreUser($row))->values();

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /** GET /crm/catalog-sellers — продавцы витрины (таблица sellers / парсер). */
    public function catalogSellers(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', 50)));
        $pageNum = max(1, (int) $request->query('page', 1));
        $search = trim((string) $request->query('search', ''));

        $q = Seller::query()->orderByDesc('id');

        if ($search !== '') {
            $like = '%'.$search.'%';
            $q->where(function ($qq) use ($like) {
                $qq->where('name', 'like', $like)->orWhere('slug', 'like', $like);
            });
        }

        $page = $q->paginate($perPage, ['*'], 'page', $pageNum);

        $data = collect($page->items())->map(function (Seller $s) {
            $crmStatus = match ($s->status) {
                'active' => 'active',
                'hidden' => 'inactive',
                'blocked' => 'blocked',
                default => 'moderation',
            };

            return [
                'id' => (string) $s->id,
                'name' => $s->name,
                'slug' => $s->slug,
                'phone' => $s->phone,
                'status' => $crmStatus,
                'rating' => $s->rating !== null ? (float) $s->rating : 0,
                'products' => (int) $s->products_count,
                'orders' => null,
                'revenue_rub' => null,
                'commission' => null,
                'complaints' => null,
                'joined_at' => $s->created_at?->toIso8601String() ?? '',
            ];
        });

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /** GET /crm/seller-reviews */
    public function sellerReviews(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', 50)));
        $pageNum = max(1, (int) $request->query('page', 1));
        $status = trim((string) $request->query('status', 'all'));

        $q = SellerReview::query()->with('seller:id,name,slug')->orderByDesc('id');

        if ($status === 'published') {
            $q->where('is_published', true);
        } elseif ($status === 'moderation') {
            $q->where('is_published', false);
        } elseif ($status === 'rejected') {
            $q->whereRaw('1 = 0');
        }

        $page = $q->paginate($perPage, ['*'], 'page', $pageNum);

        $data = collect($page->items())->map(function (SellerReview $r) {
            $sellerName = $r->seller?->name ?? '—';

            return [
                'id' => (string) $r->id,
                'seller_title' => $sellerName,
                'seller_slug' => $r->seller?->slug,
                'user_name' => $r->author_name,
                'rating' => (int) $r->rating,
                'text' => $r->body,
                'status' => $r->is_published ? 'published' : 'moderation',
                'complaints' => 0,
                'seller_reply' => null,
                'created_at' => $r->created_at?->toIso8601String() ?? '',
            ];
        });

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /** PATCH /crm/seller-reviews/{id} */
    public function updateSellerReview(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'is_published' => ['required', 'boolean'],
        ]);

        $review = SellerReview::query()->find($id);
        if (! $review) {
            return response()->json(['message' => 'Отзыв не найден'], 404);
        }

        $review->update(['is_published' => $validated['is_published']]);

        return response()->json([
            'data' => [
                'id' => (string) $review->id,
                'status' => $review->is_published ? 'published' : 'moderation',
            ],
        ]);
    }

    /** GET /crm/activity-feed — живые события для экрана уведомлений (без отдельной таблицы inbox). */
    public function activityFeed(): JsonResponse
    {
        $orders = CustomerOrder::query()
            ->orderByDesc('id')
            ->limit(35)
            ->get(['id', 'number', 'status', 'payment_status', 'total_amount', 'created_at']);

        $webhooks = PaymentWebhookLog::query()
            ->where('status', 'failed')
            ->orderByDesc('id')
            ->limit(20)
            ->get(['id', 'provider', 'error', 'created_at']);

        $items = [];

        foreach ($orders as $o) {
            $items[] = [
                'id' => 'order-'.$o->id,
                'type' => 'order',
                'title' => 'Заказ №'.$o->number,
                'message' => 'Статус: '.$o->status.', оплата: '.$o->payment_status.', сумма '.$o->total_amount.' ₽',
                'created_at' => $o->created_at?->toIso8601String(),
            ];
        }

        foreach ($webhooks as $w) {
            $items[] = [
                'id' => 'webhook-'.$w->id,
                'type' => 'payment',
                'title' => 'Ошибка webhook ('.$w->provider.')',
                'message' => (string) ($w->error ?? 'без текста'),
                'created_at' => $w->created_at?->toIso8601String(),
            ];
        }

        usort($items, fn ($a, $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));

        return response()->json(['data' => array_slice($items, 0, 50)]);
    }

    /** GET /crm/marketplace-tenant — один контур витрины по настройкам + факты из БД. */
    public function marketplaceTenant(): JsonResponse
    {
        $settings = $this->marketplaceSettings->all();
        $name = (string) ($settings['marketplace_name'] ?? 'Cheepy');
        $commission = isset($settings['default_commission_percent']) ? (float) $settings['default_commission_percent'] : 10.0;

        $frontend = rtrim((string) config('app.frontend_url', ''), '/');
        $host = parse_url($frontend, PHP_URL_HOST);
        $domain = is_string($host) && $host !== '' ? $host : '—';

        $maintenance = (bool) ($settings['maintenance_enabled'] ?? false);
        $status = $maintenance ? 'setup' : 'active';

        $payload = [[
            'id' => 'primary',
            'name' => $name,
            'slug' => Str::slug($name) ?: 'marketplace',
            'domain' => $domain,
            'logo' => '🛍️',
            'currency' => (string) ($settings['default_currency'] ?? 'RUB'),
            'commission' => round($commission, 2),
            'regions' => [],
            'status' => $status,
            'sellers_count' => Seller::query()->where('status', 'active')->count(),
            'users_count' => User::query()->count(),
            'products_count' => SystemProduct::query()->where('status', SystemProduct::STATUS_PUBLISHED)->count(),
            /** Дата создания записи настроек в settings не версионируется — показываем «сейчас» как тех. заглушку */
            'created_at' => User::query()->min('created_at') ?: now()->toDateString(),
        ]];

        return response()->json(['data' => $payload]);
    }

    /**
     * @return array{0: Carbon, 1: Carbon, 2: int}
     */
    private function resolvePeriod(Request $request): array
    {
        $raw = trim((string) $request->query('period', '30d'));
        $days = match ($raw) {
            '7d' => 7,
            '180d', '6m' => 180,
            '365d', '1y' => 365,
            default => 30,
        };

        $end = Carbon::now();
        $start = Carbon::now()->subDays($days)->startOfDay();

        return [$start, $end, $days];
    }

    /**
     * @return array{Carbon}
     */
    private function previousWindow(Carbon $start, Carbon $end): array
    {
        $len = max(1, $start->diffInSeconds($end));

        /** @phpstan-ignore-next-line */
        $prevEnd = $start->copy()->subSecond();

        return [$prevEnd->copy()->subSeconds($len)];
    }

    private function paidBaseQuery(?Carbon $start = null, ?Carbon $end = null)
    {
        $q = CustomerOrder::query()->where('payment_status', 'paid');
        if ($start && $end) {
            $q->whereRaw(
                'COALESCE(paid_at, updated_at, created_at) BETWEEN ? AND ?',
                [$start, $end]
            );
        }

        return $q;
    }

    private function paidRevenueRub(Carbon $start, Carbon $end): int
    {
        return (int) $this->paidBaseQuery($start, $end)->sum('total_amount');
    }

    private function paidOrdersCount(Carbon $start, Carbon $end): int
    {
        return (int) $this->paidBaseQuery($start, $end)->count();
    }

    /** @return list<array{label:string, revenue_rub:int, orders:int}> */
    private function salesChartRows(Carbon $start, Carbon $end, int $days): array
    {
        $useMonth = $days > 60;
        $driver = DB::getDriverName();
        /** @phpstan-ignore-next-line */
        $expr = match (true) {
            $useMonth && $driver === 'pgsql' => "to_char(COALESCE(paid_at, updated_at, created_at), 'YYYY-MM')",
            $useMonth => "DATE_FORMAT(COALESCE(paid_at, updated_at, created_at), '%Y-%m')",
            $driver === 'pgsql' => "to_char((COALESCE(paid_at, updated_at, created_at))::date, 'YYYY-MM-DD')",
            default => 'DATE(COALESCE(paid_at, updated_at, created_at))',
        };

        $rows = CustomerOrder::query()
            ->where('payment_status', 'paid')
            ->whereBetween(DB::raw('COALESCE(paid_at, updated_at, created_at)'), [$start, $end])
            ->selectRaw($expr.' as bucket, SUM(total_amount) as revenue_rub, COUNT(*) as cnt')
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get();

        return $rows
            ->map(fn ($r) => [
                'label' => (string) $r->bucket,
                'revenue_rub' => (int) $r->revenue_rub,
                'orders' => (int) $r->cnt,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{id:int, number:string, user_name:?string, user_email:?string, total_amount:int, status:string, payment_status:string, created_at:?string}>
     */
    private function recentPaidOrders(int $limit): array
    {
        $rows = CustomerOrder::query()
            ->with(['user:id,name,email'])
            ->where('payment_status', 'paid')
            ->orderByDesc(DB::raw('COALESCE(paid_at, updated_at, created_at)'))
            ->limit($limit)
            ->get();

        return $rows->map(fn (CustomerOrder $o) => [
            'id' => (int) $o->id,
            'number' => $o->number,
            'user_name' => $o->user?->name,
            'user_email' => $o->user?->email,
            'total_amount' => (int) $o->total_amount,
            'status' => $o->status,
            'payment_status' => $o->payment_status,
            'created_at' => $o->created_at?->toIso8601String(),
        ])->values()->all();
    }

    /**
     * @return list<array{product_id:?int, title:string, sold:int, revenue_rub:int}>
     */
    private function topProducts(Carbon $start, Carbon $end, int $limit): array
    {
        $rows = CustomerOrderItem::query()
            ->join('customer_orders as co', 'co.id', '=', 'customer_order_items.order_id')
            ->where('co.payment_status', 'paid')
            ->whereBetween(DB::raw('COALESCE(co.paid_at, co.updated_at, co.created_at)'), [$start, $end])
            ->selectRaw('customer_order_items.product_id as pid, MAX(customer_order_items.product_name) as title, SUM(customer_order_items.quantity) as sold, SUM(customer_order_items.total_price) as revenue')
            ->groupBy('customer_order_items.product_id')
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get();

        return $rows
            ->map(fn ($r) => [
                'product_id' => $r->pid !== null ? (int) $r->pid : null,
                'title' => (string) $r->title,
                'sold' => (int) $r->sold,
                'revenue_rub' => (int) $r->revenue,
            ])
            ->values()
            ->all();
    }

    private function topCatalogCategoryName(Carbon $start, Carbon $end): ?string
    {
        $row = CustomerOrderItem::query()
            ->join('customer_orders as co', 'co.id', '=', 'customer_order_items.order_id')
            ->leftJoin('system_products as sp', 'sp.id', '=', 'customer_order_items.product_id')
            ->leftJoin('catalog_categories as cc', 'cc.id', '=', 'sp.category_id')
            ->where('co.payment_status', 'paid')
            ->whereBetween(DB::raw('COALESCE(co.paid_at, co.updated_at, co.created_at)'), [$start, $end])
            ->whereNotNull('cc.name')
            ->selectRaw('cc.name as cat, SUM(customer_order_items.total_price) as rev')
            ->groupBy('cc.name')
            ->orderByDesc('rev')
            ->first();

        return $row?->cat !== null ? (string) $row->cat : null;
    }

    /**
     * @return list<array{name:string, revenue_rub:int, orders:int}>
     */
    private function categoryRevenue(Carbon $start, Carbon $end): array
    {
        $rows = CustomerOrderItem::query()
            ->join('customer_orders as co', 'co.id', '=', 'customer_order_items.order_id')
            ->leftJoin('system_products as sp', 'sp.id', '=', 'customer_order_items.product_id')
            ->leftJoin('catalog_categories as cc', 'cc.id', '=', 'sp.category_id')
            ->where('co.payment_status', 'paid')
            ->whereBetween(DB::raw('COALESCE(co.paid_at, co.updated_at, co.created_at)'), [$start, $end])
            ->selectRaw('COALESCE(cc.name, \'Без категории\') as cname, SUM(customer_order_items.total_price) as revenue, SUM(customer_order_items.quantity) as qty')
            ->groupBy('cname')
            ->orderByDesc('revenue')
            ->limit(12)
            ->get();

        return $rows
            ->map(fn ($r) => [
                'name' => (string) $r->cname,
                'revenue_rub' => (int) $r->revenue,
                /** @phpstan-ignore-next-line count orders approximate by lines */
                'orders' => (int) $r->qty,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{label:string, orders:int, revenue_rub:int}>
     */
    private function geoDelivery(Carbon $start, Carbon $end): array
    {
        /** В снимке доставки нет города — группируем по режиму доставки. */
        $driver = DB::getDriverName();
        $bucketExpr = match ($driver) {
            'pgsql' => "LOWER(TRIM(COALESCE(co.delivery_snapshot->>'mode', CAST(co.delivery_type AS VARCHAR), 'unknown')))",
            default => 'LOWER(TRIM(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(co.delivery_snapshot, \'$.mode\')), co.delivery_type, \'unknown\')))',
        };

        $rows = DB::table('customer_orders as co')
            ->where('co.payment_status', 'paid')
            ->whereBetween(DB::raw('COALESCE(co.paid_at, co.updated_at, co.created_at)'), [$start, $end])
            ->selectRaw($bucketExpr.' AS bucket_key, COUNT(*) AS cnt, SUM(co.total_amount) AS rev')
            ->groupBy(DB::raw($bucketExpr))
            ->orderByDesc('rev')
            ->limit(15)
            ->get();

        return $rows->map(fn ($r) => [
            'label' => $this->geoLabelRus((string) ($r->bucket_key ?? '')),
            'orders' => (int) $r->cnt,
            'revenue_rub' => (int) $r->rev,
        ])->values()->all();
    }

    /**
     * @return list<array{stage:string, count:int}>
     */
    private function orderStatusFunnel(Carbon $start, Carbon $end): array
    {
        /** Воронка по статусам заказов без веб‑аналитики: только факты по периоду. */
        $rows = CustomerOrder::query()
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->orderByDesc('cnt')
            ->get();

        return $rows
            ->map(fn ($r) => ['stage' => (string) $r->status, 'count' => (int) $r->cnt])
            ->values()
            ->all();
    }

    /**
     * @return array<string, int>
     */
    private function paymentCounts(): array
    {
        $rows = DB::table('payments')
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->all();

        $out = [];
        foreach ($rows as $k => $v) {
            $out[(string) $k] = (int) $v;
        }

        return $out;
    }

    private function geoLabelRus(string $mode): string
    {
        $m = strtolower(trim($mode));

        return match ($m) {
            'integrations_min', 'carrier' => 'Доставка (интеграции)',
            'flat_fallback', 'delivery_type', 'delivery' => 'Фикс. доставка',
            '', 'unknown', '—', '-' => 'Не указано',
            default => $m,
        };
    }

    /**
     * @param object $row user model attrs + joins
     * @return array<string, mixed>
     */
    private function serializeStoreUser(object $row): array
    {
        $sellerStatus = $row->seller_status ?? null;

        return [
            'id' => (string) $row->id,
            'name' => $row->name,
            'email' => $row->email ?? '',
            'phone' => $row->phone ?? '',
            'role' => (string) ($row->account_role ?? 'customer'),
            'status' => $sellerStatus === 'rejected' ? 'blocked' : 'active',
            'orders' => (int) ($row->orders_count ?? 0),
            'total_spent' => (int) ($row->total_spent ?? 0),
            'balance' => (int) ($row->wallet_balance ?? 0),
            'registered_at' => $row->created_at ?? '',
            'last_active' => '',
        ];
    }
}
