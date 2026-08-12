<?php

namespace App\Support;

class WithdrawalVerificationPresentation
{
    public const LABELS = [
        'available_balance_verified' => 'Доступный баланс подтверждён',
        'lock_period_verified' => 'Срок доступности капитала проверен',
        'fifo_allocation_verified' => 'Покрытие FIFO проверено',
        'fee_verified' => 'Сохранённая комиссия проверена',
        'wallet_snapshot_exists' => 'Реквизиты кошелька сохранены',
        'investor_identity_verified' => 'Личность инвестора проверена',
        'withdrawal_reason_checked' => 'Основание вывода проверено',
        'wallet_address_verified' => 'Адрес кошелька проверен',
        'network_verified' => 'Сеть проверена',
        'wallet_owner_verified' => 'Владелец кошелька проверен',
        'amount_confirmed' => 'Сумма выплаты подтверждена',
        'final_reconciliation' => 'Финальная сверка выполнена',
    ];

    public static function label(string $key): string { return self::LABELS[$key] ?? $key; }
}
