<?php

namespace App\Services\Payments;

use App\Models\SaasApiKey;
use App\Support\FrontendUrl;
use App\Support\PaymentHttp;
use App\Support\PaymentWebhookCurrency;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TinkoffProvider implements PaymentProviderInterface
{
    public function __construct(
        private array $config = []
    ) {}

    public function normalizeAmount(float $amount): int
    {
        return (int) round($amount * 100);
    }

    public function createCheckout(?SaasApiKey $apiKey, float $amount, array $context = []): array
    {
        if (empty($this->config['terminal_key']) || empty($this->config['password'])) {
            throw new \RuntimeException('Invalid provider config');
        }

        $paymentId = (int) ($context['payment_id'] ?? 0);
        $orderId = 'pay_'.$paymentId;

        $notificationUrl = $this->config['notification_url'] ?? rtrim(config('app.url', ''), '/').'/api/v1/webhook/tinkoff';
        $returnToken = (string) ($context['return_token'] ?? '');
        $urls = $this->buildReturnUrls($paymentId, $returnToken);

        $payload = [
            'TerminalKey' => $this->config['terminal_key'] ?? '',
            'Amount' => (int) round($amount * 100),
            'OrderId' => $orderId,
            'Description' => $context['description'] ?? ($apiKey !== null ? 'API Key Top-up #'.$apiKey->id : 'Оплата заказа'),
            'NotificationURL' => $notificationUrl,
            'SuccessURL' => $urls['success'],
            'FailURL' => $urls['fail'],
        ];

        $receipt = $this->buildReceipt($amount, $context);
        if ($receipt !== null) {
            $payload['Receipt'] = $receipt;
        }

        $payload['Token'] = $this->generateRequestToken($payload);

        // DEMO и prod терминалы ходят на securepay; rest-api-test.tinkoff.ru часто отдаёт 403 с VPS.
        $base = 'https://securepay.tinkoff.ru';

        $response = PaymentHttp::client(10)
            ->asJson()
            ->post($base.'/v2/Init', $payload);

        $body = $response->json();
        if (! ($body['Success'] ?? false)) {
            $message = $body['Message'] ?? $body['Details'] ?? 'Tinkoff Init failed';
            Log::warning('Tinkoff Init failed', [
                'error_code' => $body['ErrorCode'] ?? null,
                'message' => $message,
                'details' => $body['Details'] ?? null,
                'order_id' => $orderId,
                'amount_kopecks' => $payload['Amount'],
                'has_receipt' => isset($payload['Receipt']),
            ]);
            throw new \RuntimeException($message);
        }

        return [
            'provider_id' => (string) ($body['PaymentId'] ?? ''),
            'checkout_url' => $body['PaymentURL'] ?? null,
            'raw' => $body,
        ];
    }

    /**
     * Полный или частичный возврат (Cancel) через T‑Банк.
     *
     * @param  non-empty-string  $terminalPaymentId  PaymentId из Init
     * @param  int|null  $amountKopecks  null — полный возврат; число — частичное в копейках
     * @return array{ok:bool,message?:string,new_state?:string,raw:mixed}
     */
    public function cancelPayment(string $terminalPaymentId, ?int $amountKopecks): array
    {
        if (empty($this->config['terminal_key']) || empty($this->config['password'])) {
            return ['ok' => false, 'message' => 'T‑Банк не настроен', 'raw' => null];
        }
        $terminalPaymentId = trim($terminalPaymentId);
        if ($terminalPaymentId === '') {
            return ['ok' => false, 'message' => 'Пустой PaymentId', 'raw' => null];
        }

        $payload = [
            'TerminalKey' => $this->config['terminal_key'] ?? '',
            'PaymentId' => $terminalPaymentId,
        ];
        if ($amountKopecks !== null && $amountKopecks > 0) {
            $payload['Amount'] = $amountKopecks;
        }
        $payload['Token'] = $this->generateRequestToken($payload);

        // DEMO и prod терминалы ходят на securepay; rest-api-test.tinkoff.ru часто отдаёт 403 с VPS.
        $base = 'https://securepay.tinkoff.ru';

        $response = PaymentHttp::client(20)->asJson()->post($base.'/v2/Cancel', $payload);
        $body = $response->json();
        if (! is_array($body)) {
            return ['ok' => false, 'message' => 'Пустой ответ T‑Банка', 'raw' => null];
        }
        $ok = ($body['Success'] ?? false) === true;
        if (! $ok) {
            $message = trim((string) ($body['Message'] ?? $body['Details'] ?? 'Отмена платежа не удалась'));

            return ['ok' => false, 'message' => $message ?: 'Отмена платежа не удалась', 'raw' => $body];
        }

        return [
            'ok' => true,
            'new_state' => strtoupper((string) ($body['Status'] ?? $body['NewState'] ?? '')),
            'raw' => $body,
        ];
    }

    public function handleWebhook(Request $request): array
    {
        if (empty($this->config['terminal_key']) || empty($this->config['password'])) {
            return ['ok' => false, 'return_ok' => true, 'provider_id' => null, 'provider_event_id' => null, 'status' => null, 'amount_total' => null, 'currency' => null];
        }

        $data = $request->all();
        if (! is_array($data) || empty($data)) {
            $content = $request->getContent();
            $data = json_decode($content, true) ?? [];
        }

        $receivedToken = (string) ($data['Token'] ?? '');
        if ($receivedToken === '') {
            return ['ok' => false, 'return_ok' => true, 'provider_id' => null, 'provider_event_id' => null, 'status' => null, 'amount_total' => null, 'currency' => null];
        }

        $expectedToken = $this->generateRequestToken($data);
        Log::info('Tinkoff WEBHOOK TOKEN CHECK', [
            'incoming' => $receivedToken,
            'calculated' => $expectedToken,
        ]);
        if (! hash_equals($expectedToken, $receivedToken)) {
            Log::warning('Tinkoff WEBHOOK EXIT', ['reason' => 'token_fail']);

            return ['ok' => false, 'return_ok' => true, 'provider_id' => null, 'provider_event_id' => null, 'status' => null, 'amount_total' => null, 'currency' => null];
        }

        $rawStatus = strtoupper((string) ($data['Status'] ?? ''));
        $status = $this->mapTinkoffStatus($rawStatus);
        Log::info('Tinkoff WEBHOOK STATUS', [
            'raw' => $rawStatus,
            'mapped' => $status,
        ]);
        $paymentId = isset($data['PaymentId']) ? (string) $data['PaymentId'] : '';
        $eventId = $paymentId !== '' && $rawStatus !== '' ? $paymentId.'_'.$rawStatus : $paymentId;
        $orderId = $data['OrderId'] ?? '';
        $amountTotal = isset($data['Amount']) ? (int) $data['Amount'] : null;
        $currencyRaw = $data['Currency'] ?? null;
        $currency = PaymentWebhookCurrency::normalize($currencyRaw !== null ? (string) $currencyRaw : '');
        if ($currency === '') {
            $currency = 'rub';
        }

        return [
            'ok' => true,
            'provider_id' => $paymentId,
            'provider_event_id' => $eventId,
            'status' => $status,
            'amount_total' => $amountTotal,
            'currency' => $currency,
            'order_id' => $orderId,
        ];
    }

    /**
     * Подпись для Init и уведомлений T-Bank: все корневые скалярные поля, кроме Token
     * и вложенных объектов (Receipt, DATA, Shops).
     *
     * @see https://www.tbank.ru/kassa/develop/api/notifications/https/
     */
    private function generateRequestToken(array $data): string
    {
        $exclude = ['Token', 'Receipt', 'DATA', 'Shops'];
        $tokenData = [];
        foreach ($data as $key => $value) {
            if (in_array($key, $exclude, true)) {
                continue;
            }
            if (is_array($value) || is_object($value)) {
                continue;
            }
            $tokenData[$key] = is_bool($value) ? (($value ? 'true' : 'false')) : (string) $value;
        }
        $tokenData['Password'] = (string) ($this->config['password'] ?? '');
        ksort($tokenData);

        return hash('sha256', implode('', $tokenData));
    }

    /**
     * Фискальный чек для prod-терминала T‑Bank (обязателен при 54‑ФЗ).
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    private function buildReceipt(float $amount, array $context): ?array
    {
        if (($this->config['send_receipt'] ?? true) === false) {
            return null;
        }

        $amountKopecks = (int) round($amount * 100);
        if ($amountKopecks <= 0) {
            return null;
        }

        $tax = (string) ($this->config['receipt_tax'] ?? 'none');
        if (! in_array($tax, ['none', 'vat0', 'vat10', 'vat20', 'vat110', 'vat120'], true)) {
            $tax = 'none';
        }

        $paymentMethod = (string) ($this->config['receipt_payment_method'] ?? 'full_prepayment');
        $defaultObject = (string) ($this->config['receipt_payment_object'] ?? 'commodity');
        $taxation = (string) ($this->config['receipt_taxation'] ?? 'usn_income');

        $items = $this->buildReceiptItems($amountKopecks, $context, $tax, $paymentMethod, $defaultObject);

        $receipt = [
            'Taxation' => $taxation,
            'Items' => $items,
        ];

        $email = trim((string) ($context['customer_email'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $receipt['Email'] = $email;
        }

        $phone = trim((string) ($context['customer_phone'] ?? ''));
        if ($phone !== '') {
            $receipt['Phone'] = $phone;
        }

        return $receipt;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return list<array<string, mixed>>
     */
    private function buildReceiptItems(
        int $amountKopecks,
        array $context,
        string $tax,
        string $paymentMethod,
        string $defaultObject,
    ): array {
        $discountRub = max(0, (int) ($context['discount_rub'] ?? 0));
        /** @var list<array<string, mixed>> $rawItems */
        $rawItems = is_array($context['receipt_items'] ?? null) ? $context['receipt_items'] : [];

        if ($rawItems === [] || $discountRub > 0) {
            return [$this->receiptItem(
                mb_substr((string) ($context['line_item_name'] ?? $context['description'] ?? 'Оплата заказа'), 0, 128),
                $amountKopecks,
                1,
                $amountKopecks,
                $tax,
                $paymentMethod,
                $defaultObject,
            )];
        }

        $items = [];
        $sum = 0;
        foreach ($rawItems as $row) {
            $qty = (float) ($row['quantity'] ?? 1);
            if ($qty <= 0) {
                $qty = 1;
            }
            $lineKopecks = (int) ($row['amount_kopecks'] ?? 0);
            if ($lineKopecks <= 0) {
                continue;
            }
            $priceKopecks = (int) ($row['price_kopecks'] ?? (int) round($lineKopecks / $qty));
            $items[] = $this->receiptItem(
                mb_substr((string) ($row['name'] ?? 'Товар'), 0, 128),
                $priceKopecks,
                $qty,
                $lineKopecks,
                $tax,
                $paymentMethod,
                (string) ($row['payment_object'] ?? $defaultObject),
            );
            $sum += $lineKopecks;
        }

        if ($items === [] || abs($sum - $amountKopecks) > 1) {
            return [$this->receiptItem(
                mb_substr((string) ($context['line_item_name'] ?? $context['description'] ?? 'Оплата заказа'), 0, 128),
                $amountKopecks,
                1,
                $amountKopecks,
                $tax,
                $paymentMethod,
                $defaultObject,
            )];
        }

        return $items;
    }

    /** @return array<string, mixed> */
    private function receiptItem(
        string $name,
        int $priceKopecks,
        float $quantity,
        int $amountKopecks,
        string $tax,
        string $paymentMethod,
        string $paymentObject,
    ): array {
        return [
            'Name' => $name,
            'Price' => $priceKopecks,
            'Quantity' => $quantity,
            'Amount' => $amountKopecks,
            'Tax' => $tax,
            'PaymentMethod' => $paymentMethod,
            'PaymentObject' => $paymentObject,
        ];
    }

    private function buildReturnUrls(int $paymentId, string $returnToken = ''): array
    {
        $base = FrontendUrl::base();
        $tokenQuery = $returnToken !== '' ? '&return_token='.urlencode($returnToken) : '';

        return [
            'success' => $base.'/payment/success?payment_id='.$paymentId.$tokenQuery,
            'fail' => $base.'/payment/fail?payment_id='.$paymentId.$tokenQuery,
        ];
    }

    private function mapTinkoffStatus(string $status): string
    {
        return match (strtoupper($status)) {
            'CONFIRMED' => 'succeeded',
            'AUTHORIZED' => 'succeeded',
            'REJECTED', 'CANCELED', 'DEADLINE_EXPIRED' => 'failed',
            default => '',
        };
    }
}
