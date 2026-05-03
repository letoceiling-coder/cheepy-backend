<?php

namespace App\Services\Storefront;

use App\Models\CustomerWalletLedger;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CustomerWalletService
{
    public function balance(User $user): int
    {
        return (int) CustomerWalletLedger::query()
            ->where('user_id', $user->id)
            ->sum('amount');
    }

    public function credit(User $user, int $amount, string $kind, string $description, array $meta = []): CustomerWalletLedger
    {
        return $this->append($user, abs($amount), $kind, $description, $meta);
    }

    public function debit(User $user, int $amount, string $kind, string $description, array $meta = []): CustomerWalletLedger
    {
        return $this->append($user, -abs($amount), $kind, $description, $meta);
    }

    private function append(User $user, int $amount, string $kind, string $description, array $meta): CustomerWalletLedger
    {
        return DB::transaction(function () use ($user, $amount, $kind, $description, $meta) {
            $balance = (int) CustomerWalletLedger::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->sum('amount');

            return CustomerWalletLedger::query()->create([
                'user_id' => $user->id,
                'amount' => $amount,
                'balance_after' => $balance + $amount,
                'currency' => 'RUB',
                'kind' => $kind,
                'description' => $description,
                'meta' => $meta,
            ]);
        });
    }
}
