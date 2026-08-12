<?php

namespace App\Support;

class SupportPresentation
{
    public static function status(string $v):string{return['new'=>'Новое','open'=>'В работе','waiting_investor'=>'Ждём ответа инвестора','resolved'=>'Решено','closed'=>'Закрыто'][$v]??$v;}
    public static function priority(string $v):string{return['normal'=>'Обычный','high'=>'Высокий','urgent'=>'Срочный'][$v]??$v;}
    public static function category(string $v):string{return['general'=>'Общий вопрос','finance'=>'Финансы','accruals'=>'Начисления','deposit'=>'Пополнение','withdrawal'=>'Вывод средств','wallet'=>'Кошелёк','technical'=>'Технический вопрос','other'=>'Другое'][$v]??$v;}
    public static function source(string $v):string{return['withdrawal_dividend'=>'Вывод дивидендов','withdrawal_capital'=>'Вывод капитала','deposit'=>'Пополнение','wallet'=>'Проверка кошелька','support'=>'Обращение в поддержку'][$v]??$v;}
}
