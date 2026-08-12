<?php

namespace App\Support;

final class AdminNotificationTarget
{
    public static function resolve(array $data): string
    {
        $id = filter_var($data['entity_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return match ($data['event'] ?? null) {
            'deposit.created', 'deposit.payment_submitted' => $id ? route('admin.deposits.index', ['deposit' => $id]) : route('admin.deposits.index'),
            'withdrawal.created' => $id ? route('admin.withdrawals.index', ['withdrawal' => $id]) : route('admin.withdrawals.index'),
            'wallet.created' => route('admin.wallets.index'),
            default => route('admin.notifications.index'),
        };
    }
}
