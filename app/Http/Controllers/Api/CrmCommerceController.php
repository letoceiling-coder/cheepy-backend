<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomerOrder;
use App\Models\Payment;
use App\Services\Payments\CrmPaymentRefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CrmCommerceController extends Controller
{
    /** GET /crm/store-orders/stats */
    public function orderStats(): JsonResponse
    {
        $byStatus = CustomerOrder::query()
            ->selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status')
            ->all();

        $byPaymentStatus = CustomerOrder::query()
            ->selectRaw('payment_status, COUNT(*) as cnt')
            ->groupBy('payment_status')
            ->pluck('cnt', 'payment_status')
            ->all();

        return response()->json([
            'data' => [
                'by_order_status' => $byStatus,
                'by_payment_status' => $byPaymentStatus,
            ],
        ]);
    }

    /** GET /crm/store-orders */
    public function orders(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', 50)));
        $pageNum = max(1, (int) $request->query('page', 1));
        $status = $request->query('status');
        $paymentStatus = $request->query('payment_status');
        $search = trim((string) $request->query('search', ''));

        $q = CustomerOrder::query()
            ->with(['user:id,name,email'])
            ->orderByDesc('id');

        if (is_string($status) && $status !== '' && $status !== 'all') {
            $q->where('status', $status);
        }
        if (is_string($paymentStatus) && $paymentStatus !== '' && $paymentStatus !== 'all') {
            $q->where('payment_status', $paymentStatus);
        }
        if ($search !== '') {
            $like = '%'.$search.'%';
            $q->where(function ($qq) use ($like) {
                $qq->where('number', 'like', $like)
                    ->orWhereHas('user', function ($uq) use ($like) {
                        $uq->where('email', 'like', $like)->orWhere('name', 'like', $like);
                    });
            });
        }

        $page = $q->paginate($perPage, ['*'], 'page', $pageNum);

        $data = collect($page->items())->map(fn (CustomerOrder $o) => $this->serializeOrderRow($o))->values();

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

    /** GET /crm/store-orders/{id} */
    public function orderShow(int $id): JsonResponse
    {
        $order = CustomerOrder::query()
            ->with(['user:id,name,email', 'items', 'payments' => fn ($q) => $q->orderByDesc('id')])
            ->find($id);
        if (!$order) {
            return response()->json(['error' => 'Заказ не найден'], 404);
        }

        return response()->json(['data' => $this->serializeOrderDetail($order)]);
    }

    /** GET /crm/store-payments/summary */
    public function paymentSummary(): JsonResponse
    {
        $rows = Payment::query()
            ->selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status')
            ->all();

        $volumeRub = (float) Payment::query()
            ->where('status', 'succeeded')
            ->sum('amount');

        return response()->json([
            'data' => [
                'counts_by_status' => $rows,
                'succeeded_volume_rub' => round($volumeRub, 2),
            ],
        ]);
    }

    /** GET /crm/store-payments */
    public function payments(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', 50)));
        $pageNum = max(1, (int) $request->query('page', 1));
        $status = $request->query('status');
        $search = trim((string) $request->query('search', ''));

        $q = Payment::query()
            ->with(['customerOrder:id,number,user_id', 'customerOrder.user:id,name,email'])
            ->orderByDesc('id');

        if (is_string($status) && $status !== '' && $status !== 'all') {
            $q->where('status', $status);
        }
        if ($search !== '') {
            $like = '%'.$search.'%';
            $q->where(function ($qq) use ($like, $search) {
                if ($search !== '' && ctype_digit($search)) {
                    $qq->where('id', (int) $search);
                }
                $qq->orWhere('provider', 'like', $like)
                    ->orWhere('provider_id', 'like', $like)
                    ->orWhere('user_email', 'like', $like)
                    ->orWhereHas('customerOrder', function ($oq) use ($like) {
                        $oq->where('number', 'like', $like);
                    });
            });
        }

        $page = $q->paginate($perPage, ['*'], 'page', $pageNum);

        $data = collect($page->items())->map(fn (Payment $p) => $this->serializePaymentRow($p))->values();

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

    /** POST /crm/store-payments/{id}/refund */
    public function paymentRefund(Request $request, int $id, CrmPaymentRefundService $refunds): JsonResponse
    {
        $validated = $request->validate([
            /** null — полный остаток; иначе часть суммы платежа (крупные единицы, как в списке) */
            'amount' => 'nullable|numeric|min:0.01',
        ]);

        try {
            $amt = isset($validated['amount']) ? (float) $validated['amount'] : null;
            $payment = $refunds->refund($id, $amt);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->serializePaymentRow($payment)]);
    }

    /**
     * Заглушка маршрута без моковых строк: в кодовой базе нет таблицы seller_payouts.
     */
    public function payoutsPlaceholder(): JsonResponse
    {
        return response()->json([
            'implemented' => false,
            'message' => 'Учёт выплат продавцам в БД ещё не подключён к API. Данные ниже не синтезируются.',
            'data' => [
                'seller_balances' => [],
                'payout_history' => [],
            ],
        ]);
    }

    private function serializeOrderRow(CustomerOrder $o): array
    {
        $u = $o->user;
        $delivery = is_array($o->delivery_snapshot) ? $o->delivery_snapshot : [];

        return [
            'id' => (int) $o->id,
            'number' => $o->number,
            'user_id' => (int) $o->user_id,
            'user_name' => $u?->name,
            'user_email' => $u?->email,
            /** Для модели один продавец (маркетплейс) — множественных продавцов в строке пока нет */
            'seller_label' => 'Витрина',
            'total_amount' => (int) $o->total_amount,
            'currency' => $o->currency,
            'status' => $o->status,
            'payment_status' => $o->payment_status,
            'delivery_label' => $this->deliverySummaryLine($delivery, $o->delivery_provider),
            'delivery_provider' => $o->delivery_provider,
            'created_at' => $o->created_at?->toIso8601String(),
            'updated_at' => $o->updated_at?->toIso8601String(),
            'paid_at' => $o->paid_at?->toIso8601String(),
        ];
    }

    private function serializeOrderDetail(CustomerOrder $o): array
    {
        $row = $this->serializeOrderRow($o);
        $delivery = is_array($o->delivery_snapshot) ? $o->delivery_snapshot : [];
        $row['delivery_snapshot'] = $delivery;
        $row['subtotal_amount'] = (int) $o->subtotal_amount;
        $row['discount_amount'] = (int) $o->discount_amount;
        $row['delivery_amount'] = (int) $o->delivery_amount;
        $row['items'] = $o->items->map(fn ($it) => [
            'id' => $it->id,
            'product_id' => $it->product_id,
            'product_name' => $it->product_name,
            'product_image' => $it->product_image,
            'quantity' => (int) $it->quantity,
            'unit_price' => (int) $it->unit_price,
            'total_price' => (int) $it->total_price,
            'attributes' => is_array($it->attributes ?? null) ? $it->attributes : null,
        ]);
        $row['payments'] = $o->payments->map(fn (Payment $p) => [
            'id' => $p->id,
            'amount' => (string) $p->amount,
            'refunded_amount' => (string) ($p->refunded_amount ?? '0'),
            'provider' => $p->provider,
            'status' => $p->status,
            'provider_id' => $p->provider_id,
            'created_at' => $p->created_at?->toIso8601String(),
        ]);

        return $row;
    }

    private function deliverySummaryLine(array $snap, ?string $provider): string
    {
        if ($snap !== []) {
            if (! empty($snap['mode'])) {
                return (string) $snap['mode'];
            }
            if (! empty($snap['integration'])) {
                return (string) $snap['integration'];
            }
        }

        return $provider ?? '—';
    }

    private function serializePaymentRow(Payment $p): array
    {
        $order = $p->customerOrder;
        $u = $order?->user;

        return [
            'id' => (int) $p->id,
            'provider' => $p->provider,
            'amount' => (string) $p->amount,
            'status' => $p->status,
            'user_email' => $p->user_email ?? $u?->email,
            'user_name' => $u?->name,
            'order_id' => $order ? (int) $order->id : null,
            'order_number' => $order?->number,
            'provider_id' => $p->provider_id,
            'created_at' => $p->created_at?->toIso8601String(),
            /** Тип платежной операции без выдумывания: checkout витрины vs SaaS */
            'kind' => $order ? 'storefront' : ($p->api_key_id !== null ? 'saas_topup' : 'other'),
        ];
    }
}
