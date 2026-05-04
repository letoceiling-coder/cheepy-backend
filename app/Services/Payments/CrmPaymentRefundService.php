<?php

namespace App\Services\Payments;

use App\Models\CustomerOrder;
use App\Models\Payment;
use App\Models\SaasApiKey;
use Illuminate\Support\Facades\DB;

class CrmPaymentRefundService
{
    public function __construct(
        private PaymentProviderManager $providerManager,
    ) {
    }

    /**
     * Полный возврат (amount = null) или частичный — в больших единицах (как в payments.amount): руб., USD и т.д.
     *
     * Провайдеры: tinkoff (Cancel), stripe (Refund по Checkout Session cs_*).
     */
    public function refund(int $paymentId, ?float $amountMajor): Payment
    {
        $snapshot = DB::transaction(function () use ($paymentId, $amountMajor): array {
            $payment = Payment::query()->lockForUpdate()->find($paymentId);
            if (! $payment) {
                throw new \RuntimeException('Платеж не найден');
            }

            $providerKey = strtolower((string) $payment->provider);
            if ($providerKey === 'atol') {
                throw new \RuntimeException('Это не эквайринг (atol), возврат не применим');
            }
            if ($providerKey === 'sber') {
                throw new \RuntimeException('CRM-возврат для Сбера пока не подключён.');
            }
            if ($providerKey !== 'tinkoff' && $providerKey !== 'stripe') {
                throw new \RuntimeException('Провайдер не поддерживает возврат из CRM: '.$payment->provider);
            }

            if (! in_array($payment->status, ['succeeded', 'partially_refunded'], true)) {
                throw new \RuntimeException('Возврат доступен только для успешных или частично возвращённых платежей');
            }

            $pid = trim((string) $payment->provider_id);
            if ($pid === '') {
                throw new \RuntimeException('У платежа нет provider_id — возврат невозможен');
            }

            $paid = (float) $payment->amount;
            $already = (float) $payment->refunded_amount;
            $refundable = round($paid - $already, 4);
            if ($refundable <= 0.0001) {
                throw new \RuntimeException('Нет суммы для возврата');
            }

            $requested = $amountMajor !== null ? round((float) $amountMajor, 4) : null;
            if ($requested !== null && $requested <= 0) {
                throw new \InvalidArgumentException('Сумма должна быть больше нуля');
            }

            $thisRefund = $requested ?? $refundable;

            // Допуск 0.005 из-за decimal в БД и ввода
            if ($thisRefund > $refundable + 0.005) {
                throw new \InvalidArgumentException('Сумма превышает остаток к возврату ('.number_format((float) $refundable, 2, '.', '').').');
            }

            $thisRefund = min($thisRefund, $refundable);

            return [
                'payment_id' => (int) $payment->id,
                'provider' => $providerKey,
                'provider_id' => $pid,
                'this_refund' => $thisRefund,
                'paid' => $paid,
                'already' => $already,
                'api_key_id' => $payment->api_key_id,
                'customer_order_id' => $payment->customer_order_id,
            ];
        });

        $gateway = $this->providerManager->getProviderIgnoreActive($snapshot['provider']);

        if ($gateway instanceof TinkoffProvider) {
            $remoteOk = $gateway->cancelPayment(
                (string) $snapshot['provider_id'],
                (int) round((float) $snapshot['this_refund'] * 100)
            );
        } elseif ($gateway instanceof StripeProvider) {
            $remoteOk = $gateway->refundCheckoutSession(
                (string) $snapshot['provider_id'],
                $gateway->normalizeAmount((float) $snapshot['this_refund'])
            );

        } else {
            throw new \RuntimeException('Внутренняя ошибка: провайдер не экземпляр Tinkoff/Stripe.');
        }

        if (! ($remoteOk['ok'] ?? false)) {
            $msg = (string) ($remoteOk['message'] ?? '');
            throw new \RuntimeException($msg !== '' ? $msg : 'Отказ эквайринга.');
        }

        return DB::transaction(function () use ($snapshot): Payment {
            $payment = Payment::query()->lockForUpdate()->find((int) $snapshot['payment_id']);
            if (! $payment || ! in_array($payment->status, ['succeeded', 'partially_refunded'], true)) {
                throw new \RuntimeException('Платеж изменился после ответа банка — обновите список и повторите при необходимости.');
            }

            $paid = (float) $payment->amount;
            $already = (float) $payment->refunded_amount;
            $remaining = round($paid - $already, 4);

            if ($remaining <= 0.0001 || (float) $snapshot['this_refund'] > $remaining + 0.005) {
                throw new \RuntimeException('Недоступный остаток к возврату (конфликт параллельных операций).');
            }

            $newRefunded = round($already + (float) $snapshot['this_refund'], 4);

            if ($snapshot['api_key_id'] !== null) {
                $key = SaasApiKey::query()->lockForUpdate()->find((int) $snapshot['api_key_id']);
                if ($key && (float) $snapshot['this_refund'] > 0) {
                    $key->balance = max(0.0, (float) $key->balance - (float) $snapshot['this_refund']);
                    $key->save();
                }
            }

            $epsilon = 0.01;
            $isFullRefund = round($paid - $newRefunded, 4) <= $epsilon;

            if ($snapshot['customer_order_id'] !== null && $isFullRefund) {
                CustomerOrder::query()->whereKey((int) $snapshot['customer_order_id'])
                    ->update(['payment_status' => 'refunded']);
            }

            $payment->refunded_amount = $newRefunded;
            $payment->status = $isFullRefund ? 'refunded' : 'partially_refunded';

            $payment->save();

            return $payment->fresh(['customerOrder:id,number,user_id', 'customerOrder.user:id,name,email']) ?? $payment;
        });
    }

}
