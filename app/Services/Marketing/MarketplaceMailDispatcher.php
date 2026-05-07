<?php

namespace App\Services\Marketing;

use App\Models\MailIntegration;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class MarketplaceMailDispatcher
{
    private static function row(): ?MailIntegration
    {
        return MailIntegration::query()->where('name', 'smtp')->first();
    }

    public static function isReady(): bool
    {
        $row = self::row();

        return $row !== null && $row->is_active && self::isConfigured($row);
    }

    private static function isConfigured(MailIntegration $row): bool
    {
        $c = $row->config ?? [];

        return trim((string) ($c['host'] ?? '')) !== ''
            && trim((string) ($c['username'] ?? '')) !== ''
            && trim((string) ($c['password'] ?? '')) !== ''
            && trim((string) ($c['from_email'] ?? '')) !== '';
    }

    private static function applyMailerConfig(MailIntegration $row): void
    {
        $c = $row->config ?? [];
        $encRaw = strtolower(trim((string) ($c['encryption'] ?? 'tls')));
        $encryption = ($encRaw === '' || $encRaw === 'none') ? null : $encRaw;

        Config::set([
            'mail.mailers.crm_marketing_smtp' => [
                'transport' => 'smtp',
                'host' => trim((string) $c['host']),
                'port' => (int) ($c['port'] ?? 587),
                'encryption' => $encryption,
                'username' => trim((string) $c['username']),
                'password' => (string) ($c['password'] ?? ''),
                'timeout' => 30,
            ],
        ]);
    }

    public static function sendHtml(string $to, string $subject, string $html): bool
    {
        $row = self::row();
        if ($row === null || ! $row->is_active || ! self::isConfigured($row)) {
            return false;
        }

        $c = $row->config ?? [];
        $fromEmail = trim((string) ($c['from_email'] ?? ''));
        $fromName = trim((string) ($c['from_name'] ?? ''));

        try {
            self::applyMailerConfig($row);
            Mail::mailer('crm_marketing_smtp')->send([], [], function ($message) use ($to, $subject, $html, $fromEmail, $fromName) {
                $message->from($fromEmail, $fromName !== '' ? $fromName : null);
                $message->to($to);
                $message->subject($subject);
                $message->html($html, 'text/html; charset=UTF-8');
            });
            $row->update(['last_successful_send_at' => now()]);

            return true;
        } catch (\Throwable $e) {
            Log::warning('crm_marketing_mail_failed', [
                'to' => $to,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
