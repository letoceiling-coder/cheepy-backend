<?php

namespace App\Support;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * HTTP client for Russian payment gateways (T-Bank, Sber, ATOL).
 * Uses bundled or system CA including Russian Trusted Root (Mincomsvyaz).
 */
final class PaymentHttp
{
    public static function client(int $timeoutSeconds = 15): PendingRequest
    {
        $options = ['verify' => self::verifyPath()];

        return Http::timeout($timeoutSeconds)->withOptions($options);
    }

    /** @return bool|string */
    public static function verifyPath(): bool|string
    {
        $configured = trim((string) config('payments.ca_bundle', ''));
        foreach (array_filter([
            $configured,
            storage_path('certs/payments-ca-bundle.pem'),
            storage_path('certs/russian-trusted-ca-bundle.pem'),
        ]) as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        $system = '/etc/ssl/certs/ca-certificates.crt';
        if (is_file($system)) {
            return $system;
        }

        return true;
    }
}
