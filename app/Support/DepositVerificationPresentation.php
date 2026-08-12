<?php

namespace App\Support;

class DepositVerificationPresentation
{
    public const LABELS = [
        'investment_terms_active' => 'У инвестора действуют условия новой инвестиции',
        'provider_opened' => 'Открыт провайдер и выбран нужный аккаунт',
        'currency_verified' => 'Проверена валюта поступления',
        'network_verified' => 'Проверена сеть',
        'address_verified' => 'Адрес получения совпадает',
        'incoming_transaction_found' => 'Входящая транзакция найдена',
        'txid_verified' => 'TXID совпадает',
        'transaction_status_verified' => 'Транзакция успешно зачислена',
        'received_amount_verified' => 'Фактически полученная сумма сверена',
        'sender_checked' => 'Источник поступления проверен',
        'fee_and_net_verified' => 'Комиссия и сумма инвестиции проверены',
        'duplicate_txid_checked' => 'Проверено отсутствие повторного зачисления TXID',
        'final_reconciliation' => 'Финальная сверка выполнена',
    ];

    public static function label(string $key): string { return self::LABELS[$key] ?? $key; }
    public static function status(string $status): string { return ['pending' => 'Ожидает', 'passed' => 'Пройдено', 'failed' => 'Ошибка'][$status] ?? $status; }
}
