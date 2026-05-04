<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\CustomerOrder;
use App\Models\CustomerPaymentMethod;
use App\Models\CustomerProfile;
use App\Models\CustomerReceipt;
use App\Models\CustomerWalletLedger;
use App\Models\PaymentProvider;
use App\Models\SocialOauthIntegration;
use App\Models\User;
use App\Models\UserAddress;
use App\Models\UserPickupPoint;
use App\Services\Storefront\CdekOfficeService;
use App\Services\Storefront\CustomerWalletService;
use App\Services\Storefront\ReferralService;
use App\Services\Storefront\YandexRuAddressEnrichmentService;
use App\Services\Storefront\YandexSuggestService;
use App\Support\SocialOauthCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class StorefrontAccountController extends Controller
{
    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->attributes->get('storefront_user');

        return $user;
    }

    public function summary(Request $request, ReferralService $referrals, CustomerWalletService $wallet): JsonResponse
    {
        $user = $this->user($request);
        $profile = CustomerProfile::query()->firstOrCreate(['user_id' => $user->id]);
        $code = $referrals->codeFor($user);

        return response()->json([
            'profile' => $this->profilePayload($user, $profile),
            'addresses' => $this->addressList($user),
            'pickup_points' => $this->pickupPointList($user),
            'wallet' => ['balance' => $wallet->balance($user), 'currency' => 'RUB'],
            'referral' => [
                'code' => $code->code,
                'link' => $referrals->linkFor($user),
                'stats' => $this->referralStats($code->id),
            ],
            'integrations' => $this->integrationAvailability(),
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email,'.$user->id],
            'phone' => ['nullable', 'string', 'max:32'],
            'birthday' => ['nullable', 'date'],
            'marketing_opt_in' => ['nullable', 'boolean'],
        ]);

        $user->update([
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
        ]);
        $profile = CustomerProfile::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'birthday' => $data['birthday'] ?? null,
                'marketing_opt_in' => (bool) ($data['marketing_opt_in'] ?? false),
            ]
        );

        return response()->json(['profile' => $this->profilePayload($user->refresh(), $profile)]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);
        if (! $user->password || ! Hash::check($data['current_password'], $user->password)) {
            return response()->json(['error' => 'Текущий пароль указан неверно'], 422);
        }
        $user->update(['password' => $data['password']]);

        return response()->json(['ok' => true]);
    }

    public function addresses(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->addressList($this->user($request))]);
    }

    public function storeAddress(Request $request, YandexRuAddressEnrichmentService $addressEnrich): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'max:80'],
            'region' => ['nullable', 'string', 'max:160'],
            'city' => ['required', 'string', 'max:160'],
            'postal_code' => ['nullable', 'string', 'max:32'],
            'line1' => ['required', 'string', 'max:500'],
            'line2' => ['nullable', 'string', 'max:255'],
            'lat' => ['nullable', 'numeric'],
            'lng' => ['nullable', 'numeric'],
            'source' => ['nullable', 'string', 'max:32'],
            'is_default' => ['nullable', 'boolean'],
            'provider_payload' => ['nullable', 'array'],
        ]);
        $data = $addressEnrich->enrichValidatedAddress($data);
        $address = DB::transaction(function () use ($user, $data) {
            if ((bool) ($data['is_default'] ?? false)) {
                UserAddress::query()->where('user_id', $user->id)->update(['is_default' => false]);
            }

            return UserAddress::query()->create([
                ...$data,
                'user_id' => $user->id,
                'country' => $data['country'] ?? 'Россия',
                'source' => $data['source'] ?? 'manual',
            ]);
        });

        return response()->json(['data' => $address], 201);
    }

    public function updateAddress(Request $request, int $id, YandexRuAddressEnrichmentService $addressEnrich): JsonResponse
    {
        $user = $this->user($request);
        $address = UserAddress::query()->where('user_id', $user->id)->findOrFail($id);
        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'max:80'],
            'region' => ['nullable', 'string', 'max:160'],
            'city' => ['required', 'string', 'max:160'],
            'postal_code' => ['nullable', 'string', 'max:32'],
            'line1' => ['required', 'string', 'max:500'],
            'line2' => ['nullable', 'string', 'max:255'],
            'lat' => ['nullable', 'numeric'],
            'lng' => ['nullable', 'numeric'],
            'source' => ['nullable', 'string', 'max:32'],
            'is_default' => ['nullable', 'boolean'],
            'provider_payload' => ['nullable', 'array'],
        ]);
        $data = $addressEnrich->enrichValidatedAddress($data);
        DB::transaction(function () use ($user, $address, $data) {
            if ((bool) ($data['is_default'] ?? false)) {
                UserAddress::query()->where('user_id', $user->id)->update(['is_default' => false]);
            }
            $address->update($data);
        });

        return response()->json(['data' => $address->refresh()]);
    }

    public function deleteAddress(Request $request, int $id): JsonResponse
    {
        UserAddress::query()->where('user_id', $this->user($request)->id)->findOrFail($id)->delete();

        return response()->json(null, 204);
    }

    public function addressSuggest(Request $request, YandexSuggestService $service): JsonResponse
    {
        $data = $request->validate(['text' => ['required', 'string', 'max:200']]);

        return response()->json($service->suggest($data['text']));
    }

    public function pickupPoints(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->pickupPointList($this->user($request))]);
    }

    public function searchPickupPoints(Request $request, CdekOfficeService $cdek): JsonResponse
    {
        $filters = $request->validate([
            'city_code' => ['nullable', 'string', 'max:32'],
            'postal_code' => ['nullable', 'string', 'max:32'],
            'country_code' => ['nullable', 'string', 'max:2'],
            'weight_max' => ['nullable', 'numeric'],
        ]);

        return response()->json($cdek->search($filters));
    }

    public function storePickupPoint(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validate([
            'provider' => ['required', 'string', 'max:32'],
            'office_code' => ['required', 'string', 'max:120'],
            'name' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:160'],
            'address' => ['required', 'string', 'max:500'],
            'lat' => ['nullable', 'numeric'],
            'lng' => ['nullable', 'numeric'],
            'work_time' => ['nullable', 'string', 'max:500'],
            'is_default' => ['nullable', 'boolean'],
            'provider_payload' => ['nullable', 'array'],
        ]);
        $point = DB::transaction(function () use ($user, $data) {
            if ((bool) ($data['is_default'] ?? false)) {
                UserPickupPoint::query()->where('user_id', $user->id)->update(['is_default' => false]);
            }

            return UserPickupPoint::query()->updateOrCreate(
                ['user_id' => $user->id, 'provider' => $data['provider'], 'office_code' => $data['office_code']],
                $data
            );
        });

        return response()->json(['data' => $point], 201);
    }

    public function deletePickupPoint(Request $request, int $id): JsonResponse
    {
        UserPickupPoint::query()->where('user_id', $this->user($request)->id)->findOrFail($id)->delete();

        return response()->json(null, 204);
    }

    public function paymentMethods(Request $request): JsonResponse
    {
        $providers = PaymentProvider::query()
            ->where('is_active', true)
            ->get(['name', 'is_active'])
            ->map(fn ($p) => ['name' => $p->name, 'is_active' => (bool) $p->is_active])
            ->values();
        $methods = CustomerPaymentMethod::query()
            ->where('user_id', $this->user($request)->id)
            ->where('is_active', true)
            ->latest()
            ->get(['id', 'provider', 'method_type', 'brand', 'last4', 'exp_month', 'exp_year', 'is_default', 'created_at']);

        return response()->json(['data' => $methods, 'providers' => $providers]);
    }

    public function deletePaymentMethod(Request $request, int $id): JsonResponse
    {
        CustomerPaymentMethod::query()
            ->where('user_id', $this->user($request)->id)
            ->where('id', $id)
            ->update(['is_active' => false]);

        return response()->json(null, 204);
    }

    public function orders(Request $request): JsonResponse
    {
        $orders = CustomerOrder::query()
            ->with('items')
            ->where('user_id', $this->user($request)->id)
            ->latest()
            ->paginate((int) $request->query('per_page', 20));

        return response()->json([
            'data' => $orders->getCollection()->map(fn (CustomerOrder $order) => $this->orderPayload($order))->values(),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'total' => $orders->total(),
            ],
        ]);
    }

    public function receipts(Request $request): JsonResponse
    {
        $rows = CustomerReceipt::query()
            ->where('user_id', $this->user($request)->id)
            ->latest('issued_at')
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function wallet(Request $request, CustomerWalletService $wallet): JsonResponse
    {
        $rows = CustomerWalletLedger::query()
            ->where('user_id', $this->user($request)->id)
            ->latest()
            ->limit(100)
            ->get();

        return response()->json([
            'balance' => $wallet->balance($this->user($request)),
            'currency' => 'RUB',
            'ledger' => $rows,
        ]);
    }

    public function coupons(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $now = now();
        $coupons = Coupon::query()
            ->where('is_active', true)
            ->where(function ($q) use ($now) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>=', $now);
            })
            ->latest()
            ->get();
        $redemptions = CouponRedemption::query()
            ->where('user_id', $user->id)
            ->get()
            ->groupBy('coupon_id');

        return response()->json([
            'data' => $coupons->map(fn (Coupon $coupon) => [
                ...$coupon->toArray(),
                'user_used_count' => $redemptions->get($coupon->id, collect())->count(),
            ])->values(),
        ]);
    }

    public function referral(Request $request, ReferralService $referrals): JsonResponse
    {
        $code = $referrals->codeFor($this->user($request));

        return response()->json([
            'code' => $code->code,
            'link' => $referrals->linkFor($this->user($request)),
            'stats' => $this->referralStats($code->id),
        ]);
    }

    public function socialProviders(): JsonResponse
    {
        $catalog = SocialOauthCatalog::providers();
        $active = SocialOauthIntegration::query()
            ->where('is_active', true)
            ->pluck('name')
            ->all();

        return response()->json([
            'providers' => collect($catalog)->map(fn ($meta, $id) => [
                'id' => $id,
                'title' => $meta['title'] ?? $id,
                'enabled' => in_array($id, $active, true),
            ])->values(),
        ]);
    }

    private function profilePayload(User $user, CustomerProfile $profile): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'birthday' => $profile->birthday?->format('Y-m-d'),
            'marketing_opt_in' => $profile->marketing_opt_in,
            'linked_social_providers' => $user->socialAccounts()->pluck('provider')->values()->all(),
        ];
    }

    private function addressList(User $user)
    {
        return UserAddress::query()->where('user_id', $user->id)->orderByDesc('is_default')->latest()->get();
    }

    private function pickupPointList(User $user)
    {
        return UserPickupPoint::query()->where('user_id', $user->id)->orderByDesc('is_default')->latest()->get();
    }

    private function integrationAvailability(): array
    {
        return [
            'delivery' => \App\Models\DeliveryIntegration::query()->where('is_active', true)->pluck('name')->values()->all(),
            'payments' => PaymentProvider::query()->where('is_active', true)->pluck('name')->values()->all(),
            'social' => SocialOauthIntegration::query()->where('is_active', true)->pluck('name')->values()->all(),
        ];
    }

    private function referralStats(int $referralCodeId): array
    {
        return [
            'clicks' => \App\Models\ReferralLinkClick::query()->where('referral_code_id', $referralCodeId)->count(),
            'registrations' => \App\Models\ReferralEvent::query()->where('referral_code_id', $referralCodeId)->where('event_type', 'registration')->count(),
            'rewarded_amount' => (int) \App\Models\ReferralEvent::query()->where('referral_code_id', $referralCodeId)->sum('reward_amount'),
        ];
    }

    private function orderPayload(CustomerOrder $order): array
    {
        return [
            ...$order->toArray(),
            'items' => $order->items->values(),
        ];
    }
}
