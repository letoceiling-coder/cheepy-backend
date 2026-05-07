<?php

namespace App\Console\Commands;

use App\Models\CustomerProfile;
use App\Models\MarketingEmailTemplate;
use App\Models\StorefrontCartSnapshot;
use App\Models\User;
use App\Services\Marketing\MarketingProductEmailBlockBuilder;
use App\Services\Marketing\TransactionalMarketingMail;
use Illuminate\Console\Command;

class SendAbandonedCartRemindersCommand extends Command
{
    protected $signature = 'marketing:send-cart-reminders {--hours-idle=3 : Минимально часов без изменения корзины}';

    protected $description = 'Отправляет письма о брошенной корзине (при синхронизации с витрины, макс. 1 раз на «сессию» корзины до очистки на checkout)';

    public function handle(TransactionalMarketingMail $mail, MarketingProductEmailBlockBuilder $blocks): int
    {
        $idleH = max(1, (int) $this->option('hours-idle'));

        $snapshots = StorefrontCartSnapshot::query()
            ->with('user')
            ->where(function ($q) use ($idleH) {
                $q->where('updated_at', '<=', now()->subHours($idleH))
                    ->where('updated_at', '>=', now()->subDays(14));
            })
            ->limit(200)
            ->get();

        $tpl = MarketingEmailTemplate::query()
            ->where('send_trigger', 'cart_abandon')
            ->where('is_active', true)
            ->where('is_automatic', true)
            ->orderByDesc('id')
            ->first();

        if ($tpl === null) {
            $this->warn('Нет активного авто-шаблона cart_abandon.');

            return self::SUCCESS;
        }

        $sent = 0;
        foreach ($snapshots as $snap) {
            $user = $snap->user;
            if (! $user instanceof User || ! $user->email) {
                continue;
            }
            if (! $this->hasMarketingOptIn($user->id)) {
                continue;
            }
            if ($snap->last_abandon_email_at !== null && $snap->last_abandon_email_at->greaterThanOrEqualTo($snap->updated_at)) {
                continue;
            }

            $built = $blocks->buildFromSnapshotItems(is_array($snap->items) ? $snap->items : []);
            if ($built === null) {
                continue;
            }
            [$html] = $built;
            if ($mail->trySendTrigger('cart_abandon', $user, ['products_block' => $html])) {
                $snap->forceFill(['last_abandon_email_at' => now()])->save();
                $sent++;
            }
        }

        $this->info("Отправлено напоминаний: {$sent}");

        return self::SUCCESS;
    }

    private function hasMarketingOptIn(int $userId): bool
    {
        $p = CustomerProfile::query()->where('user_id', $userId)->first();

        return $p !== null && $p->marketing_opt_in;
    }
}
