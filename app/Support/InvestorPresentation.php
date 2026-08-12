<?php

namespace App\Support;

class InvestorPresentation
{
    private const STATUSES = [
        'active' => 'Активен', 'closed' => 'Закрыт', 'confirmed' => 'Подтверждено',
        'pending' => 'Ожидает обработки', 'payment_submitted' => 'Оплата отправлена', 'submitted' => 'На проверке', 'new' => 'Новая',
        'review' => 'На проверке', 'approved' => 'Одобрена', 'paid' => 'Выплачена',
        'cancelled' => 'Отменена', 'rejected' => 'Отклонена', 'blocked' => 'Заблокирован',
        'archived' => 'Архивирован', 'completed' => 'Завершено', 'calculated' => 'Рассчитано',
    ];

    private const TYPES = [
        'dividend' => 'Дивиденды', 'capital' => 'Капитал', 'deposit' => 'Пополнение',
        'capitalization' => 'Капитализация', 'withdrawal' => 'Вывод', 'reversal' => 'Сторно',
        'dividend_withdrawal' => 'Вывод дивидендов', 'capital_withdrawal' => 'Вывод капитала',
        'dividend_payment' => 'Выплата дивидендов',
    ];

    public static function status(?string $value): string
    {
        return self::STATUSES[$value] ?? '—';
    }

    public static function type(?string $value): string
    {
        return self::TYPES[$value] ?? 'Операция';
    }

    public static function adminDepositStatus(?string $value): string
    {
        return [
            'pending' => 'Новая', 'payment_submitted' => 'Оплачено инвестором', 'submitted' => 'Проверка', 'confirmed' => 'Подтверждено',
            'rejected' => 'Отклонено', 'cancelled' => 'Отменено',
        ][$value] ?? '—';
    }

    public static function investorDepositStatus(?string $value): string
    {
        return [
            'pending' => 'Заявка создана', 'payment_submitted' => 'Оплата отправлена на проверку', 'submitted' => 'Проверяется администратором',
            'confirmed' => 'Пополнение подтверждено', 'rejected' => 'Отклонено',
            'cancelled' => 'Отменено',
        ][$value] ?? '—';
    }

    public static function investorWithdrawalStatus(?string $value): string
    {
        return [
            'new' => 'Создан', 'review' => 'На проверке', 'approved' => 'Одобрен',
            'paid' => 'Выплачен', 'rejected' => 'Отклонён', 'cancelled' => 'Отменён',
        ][$value] ?? '—';
    }
}
