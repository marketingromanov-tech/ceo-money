<?php

namespace App\Support;

use App\Models\DepositAddress;

class WalletPresentation
{
    private const WALLET_STATUSES = [
        'pending' => 'На проверке',
        'approved' => 'Одобрен',
        'blocked' => 'Заблокирован',
        'archived' => 'Архивирован',
    ];

    public static function walletStatus(string $status): string
    {
        return self::WALLET_STATUSES[$status] ?? '—';
    }

    public static function addressStatus(DepositAddress $address): string
    {
        return $address->archived_at !== null ? 'Архивирован' : ($address->is_active ? 'Активен' : 'Неактивен');
    }

    public static function shortAddress(?string $address): string
    {
        if ($address === null || $address === '') {
            return '—';
        }

        return mb_strlen($address) > 12 ? mb_substr($address, 0, 6).'…'.mb_substr($address, -4) : $address;
    }
}
