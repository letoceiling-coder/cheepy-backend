<?php

namespace App\Jobs;

use App\Models\Payment;
use App\Services\Payments\AtolFiscalService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendAtolReceiptJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public int $paymentId
    ) {
        $this->onQueue('atol');
    }

    public function handle(): void
    {
        $payment = Payment::find($this->paymentId);
        if (!$payment) {
            Log::warning('ATOL JOB: payment not found', ['payment_id' => $this->paymentId]);
            return;
        }

        if ($payment->atol_uuid || $payment->atol_status === 'done') {
            Log::info('ATOL JOB: skipped, already fiscalized', ['payment_id' => $payment->id, 'atol_uuid' => $payment->atol_uuid]);
            return;
        }

        $email = $payment->user_email ?? config('payments.atol_default_email', 'client@example.com');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Log::error('ATOL JOB: invalid email', ['payment_id' => $payment->id, 'email' => $email]);
            $payment->update(['atol_status' => 'failed']);
            return;
        }

        $service = AtolFiscalService::fromProvider('atol');
        if ($service === null) {
            Log::warning('ATOL JOB: provider not configured', ['payment_id' => $payment->id]);
            $payment->update(['atol_status' => 'failed']);
            return;
        }

        $uuid = $service->createReceipt($payment, $email);
        if ($uuid !== null) {
            $payment->update(['atol_uuid' => $uuid, 'atol_status' => 'done']);
            Log::info('ATOL JOB: receipt created', ['payment_id' => $payment->id, 'atol_uuid' => $uuid]);
        } else {
            Log::error('ATOL JOB: createReceipt failed', ['payment_id' => $payment->id]);
            throw new \RuntimeException('ATOL receipt creation failed');
        }
    }

    public function failed(\Throwable $exception): void
    {
        $payment = Payment::find($this->paymentId);
        if ($payment && $payment->atol_status !== 'done') {
            $payment->update(['atol_status' => 'failed']);
        }
        Log::error('ATOL JOB: failed after retries', [
            'payment_id' => $this->paymentId,
            'error' => $exception->getMessage(),
        ]);
    }
}
