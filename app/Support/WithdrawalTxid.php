<?php

namespace App\Support;

use DomainException;

final class WithdrawalTxid
{
    public static function normalize(?string $txid): ?string
    {
        if ($txid === null) {
            return null;
        }

        $txid = mb_strtolower(trim($txid));
        if ($txid === '') {
            return null;
        }
        if (mb_strlen($txid) > 255) {
            throw new DomainException('TXID не должен превышать 255 символов.');
        }

        return $txid;
    }
}
