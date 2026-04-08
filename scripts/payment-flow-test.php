<?php
/**
 * Payment flow test script - run on server via: php scripts/payment-flow-test.php
 * Or: php artisan tinker < scripts/payment-flow-test.php
 */
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\SaasApiKey;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

echo "=== STEP 1: Create API key ===\n";
$plain = 'sk_live_' . Str::random(40);
$k = SaasApiKey::create([
    'name' => 'test-key',
    'api_key_hash' => SaasApiKey::hashKey($plain),
    'requests_per_minute' => 60,
    'balance' => 0,
    'cost_per_request' => 0.001,
    'is_active' => true,
]);
$apiKeyId = $k->id;
echo "Created api_key_id=$apiKeyId, balance=" . (float)$k->balance . "\n";

echo "\n=== STEP 2: Initial balance ===\n";
$row = DB::table('saas_api_keys')->where('id', $apiKeyId)->first();
echo "id=$row->id balance=$row->balance\n";

$providerId = 'test_pay_' . time();
$providerEventId = 'event_' . $apiKeyId . '_' . time();
echo "\n=== STEP 3: Insert payment (pending) ===\n";
DB::table('payments')->insert([
    'api_key_id' => $apiKeyId,
    'amount' => 100.0000,
    'provider' => 'stripe',
    'status' => 'pending',
    'provider_id' => $providerId,
    'provider_event_id' => null,
    'created_at' => now(),
    'updated_at' => now(),
]);
echo "Inserted payment (pending, provider_id=$providerId, provider_event_id=null)\n";

echo "\n=== STEP 4: Apply webhook logic (add balance) ===\n";
DB::transaction(function () use ($providerId, $providerEventId) {
    $alreadyProcessed = DB::table('payments')
        ->where('provider', 'stripe')
        ->where('provider_event_id', $providerEventId)
        ->exists();
    if ($alreadyProcessed) {
        echo "Idempotency: already processed, skip\n";
        return;
    }
    $payment = DB::table('payments')
        ->where('provider', 'stripe')
        ->where('provider_id', $providerId)
        ->lockForUpdate()
        ->first();
    if (!$payment || $payment->status === 'succeeded') {
        echo "Payment not found or already succeeded\n";
        return;
    }
    $key = SaasApiKey::query()->whereKey($payment->api_key_id)->lockForUpdate()->first();
    if ($key) {
        $key->balance = (float)$key->balance + (float)$payment->amount;
        $key->save();
        echo "Added balance: +{$payment->amount}\n";
    }
    DB::table('payments')->where('id', $payment->id)->update([
        'status' => 'succeeded',
        'provider_event_id' => $providerEventId,
        'updated_at' => now(),
    ]);
});

echo "\n=== STEP 5: Balance after ===\n";
$row = DB::table('saas_api_keys')->where('id', $apiKeyId)->first();
$balanceAfter = (float)$row->balance;
echo "balance=$balanceAfter\n";

echo "\n=== STEP 6: Idempotency - run same logic again ===\n";
$beforeIdempotency = $balanceAfter;
DB::transaction(function () use ($providerId, $providerEventId) {
    $alreadyProcessed = DB::table('payments')
        ->where('provider', 'stripe')
        ->where('provider_event_id', $providerEventId)
        ->exists();
    if ($alreadyProcessed) {
        echo "Idempotency: already processed, skip (OK)\n";
        return;
    }
    $payment = DB::table('payments')
        ->where('provider', 'stripe')
        ->where('provider_id', $providerId)
        ->lockForUpdate()
        ->first();
    if (!$payment || $payment->status === 'succeeded') {
        echo "Payment already succeeded, skip (OK)\n";
        return;
    }
    $key = SaasApiKey::query()->whereKey($payment->api_key_id)->lockForUpdate()->first();
    if ($key) {
        $key->balance = (float)$key->balance + (float)$payment->amount;
        $key->save();
        echo "WARN: Balance added again - idempotency FAILED\n";
    }
});

echo "\n=== STEP 7: Verify ===\n";
$row = DB::table('saas_api_keys')->where('id', $apiKeyId)->first();
$balanceFinal = (float)$row->balance;
$paymentsCount = DB::table('payments')->where('api_key_id', $apiKeyId)->where('provider_event_id', $providerEventId)->count();

echo "balance=$balanceFinal (expected $balanceAfter, should NOT increase)\n";
echo "payments with event_123: $paymentsCount (should be 1)\n";

if (abs($balanceFinal - $balanceAfter) < 0.0001 && $balanceAfter >= 100) {
    echo "\n✔ balance NOT increased second time\n";
} else {
    echo "\n✗ balance changed or wrong\n";
}
if ($paymentsCount === 1) {
    echo "✔ payment NOT duplicated\n";
} else {
    echo "✗ payment duplicated\n";
}
