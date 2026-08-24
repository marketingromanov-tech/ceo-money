<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\DepositAddress;
use App\Models\DepositRequest;
use App\Models\DepositVerificationCheck;
use App\Models\DividendCapitalization;
use App\Models\FeeRule;
use App\Models\InvestmentTerm;
use App\Models\InvestmentProgram;
use App\Models\InvestmentTransaction;
use App\Models\InvestorWallet;
use App\Models\Investor;
use App\Models\WithdrawalRequest;
use App\Models\WithdrawalVerificationCheck;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AuditPresentation
{
    private const ACTIONS = [
        'admin.created'=>['Создан администратор','administrative'],
        'admin.activated'=>['Администратор активирован','administrative'],
        'admin.deactivated'=>['Администратор деактивирован','administrative'],
        'admin.password_reset'=>['Пароль администратора сброшен','administrative'],
        'investment_program.created'=>['Создана инвестиционная программа','administrative'],
        'investment_program.updated'=>['Изменена инвестиционная программа','administrative'],
        'investment_program.archived'=>['Инвестиционная программа архивирована','administrative'],
        'investment_program.version_created'=>['Создана версия инвестиционной программы','administrative'],
        'investment_program.version_updated'=>['Обновлены условия инвестиционной программы','administrative'],
        'deposit_request.created'=>['Создана заявка на пополнение','financial'], 'deposit_request.submitted'=>['Пополнение принято на проверку','financial'],
        'deposit_request.confirmed'=>['Пополнение подтверждено','financial'], 'deposit_request.rejected'=>['Пополнение отклонено','financial'],
        'deposit_request.cancelled'=>['Пополнение отменено','financial'],
        'deposit_verification.passed'=>['Проверка пополнения пройдена','financial'],
        'deposit_verification.failed'=>['Проверка пополнения отмечена ошибочной','financial'],
        'deposit_verification.reset'=>['Проверка пополнения сброшена','financial'],
        'withdrawal.created'=>['Создана заявка на вывод','financial'], 'investor.created'=>['Создан инвестор','administrative'],
        'support_ticket.created'=>['Создано обращение','administrative'], 'support_ticket.assigned'=>['Обращение назначено','administrative'],
        'support_ticket.status_changed'=>['Статус обращения изменён','administrative'], 'support_ticket.message_sent'=>['Отправлено сообщение в обращении','administrative'],
        'withdrawal.review'=>['Заявка на вывод принята на проверку','financial'], 'withdrawal.approved'=>['Заявка на вывод одобрена','financial'],
        'withdrawal.cancelled'=>['Заявка на вывод отменена','financial'], 'withdrawal.rejected'=>['Заявка на вывод отклонена','financial'],
        'withdrawal.dividend_paid'=>['Дивиденды выплачены','financial'], 'withdrawal.capital_paid'=>['Капитал выплачен','financial'],
        'withdrawal_verification.passed'=>['Проверка выплаты пройдена','financial'],
        'withdrawal_verification.failed'=>['Проверка выплаты отмечена ошибочной','financial'],
        'withdrawal_verification.reset'=>['Проверка выплаты сброшена','financial'],
        'investment_transaction.reversed'=>['Финансовая операция сторнирована','financial'], 'dividend_capitalization'=>['Дивиденды капитализированы','financial'],
        'fee_rule_created'=>['Создано правило комиссии','administrative'], 'fee_rule_updated'=>['Изменено правило комиссии','administrative'],
        'fee_rule_activated'=>['Правило комиссии активировано','administrative'], 'fee_rule_deactivated'=>['Правило комиссии отключено','administrative'],
        'investment_term_created'=>['Изменены условия инвестирования','administrative'],
        'investor_wallet.approved'=>['Кошелёк инвестора одобрен','administrative'], 'investor_wallet.blocked'=>['Кошелёк инвестора заблокирован','administrative'],
        'investor_wallet.archived'=>['Кошелёк инвестора архивирован','administrative'], 'investor_wallet.archived_by_investor'=>['Кошелёк архивирован инвестором','administrative'],
        'deposit_address.created'=>['Создан адрес пополнения','administrative'], 'deposit_address.assigned'=>['Адрес пополнения назначен','administrative'],
        'deposit_address.unassigned'=>['Адрес пополнения освобождён','administrative'], 'deposit_address.archived'=>['Адрес пополнения архивирован','administrative'],
    ];

    private const OBJECTS = [
        User::class=>'Администратор',
        InvestmentProgram::class=>'Инвестиционная программа',
        FeeRule::class=>'Правило комиссии', InvestmentTerm::class=>'Условия инвестирования', InvestorWallet::class=>'Кошелёк инвестора',
        DepositAddress::class=>'Адрес пополнения', DepositRequest::class=>'Заявка на пополнение', DepositVerificationCheck::class=>'Проверка пополнения', WithdrawalRequest::class=>'Заявка на вывод', WithdrawalVerificationCheck::class=>'Проверка выплаты', Investor::class=>'Инвестор', InvestmentTransaction::class=>'Финансовая операция',
        DividendCapitalization::class=>'Капитализация дивидендов',
        SupportTicket::class=>'Обращение',
    ];

    private const FIELDS = [
        'program_id'=>'ID программы','version_id'=>'ID версии','min_amount'=>'Минимальная сумма','max_amount'=>'Максимальная сумма','is_partial_withdrawal_allowed'=>'Частичный вывод',
        'status'=>'Статус','monthly_rate'=>'Ставка','lock_months'=>'Капитал доступен через','minimum_balance'=>'Минимальный остаток',
        'minimum_dividend_withdrawal'=>'Минимальный вывод дивидендов','partial_withdrawal_allowed'=>'Частичный вывод','is_active'=>'Активно',
        'investor_id'=>'Инвестор ID','currency'=>'Валюта','network'=>'Сеть','address'=>'Адрес','provider'=>'Provider','payer'=>'Плательщик',
        'percent_value'=>'Процент','fixed_value'=>'Фиксированная комиссия','minimum_fee'=>'Минимальная комиссия','maximum_fee'=>'Максимальная комиссия',
        'valid_from'=>'Действует с','valid_to'=>'Действует по','requested_amount'=>'Запрошено','reserved_amount'=>'Зарезервировано','gross_amount'=>'Сумма до комиссии',
        'net_amount'=>'К выплате','capitalized_amount'=>'Переведено в капитал','fee'=>'Комиссия','fee_amount'=>'Комиссия','amount'=>'Сумма',
        'reason'=>'Причина','rejected_reason'=>'Причина отклонения','cancellation_reason'=>'Причина отмены','txid'=>'TXID','reversal_id'=>'Сторно ID','is_personal'=>'Персональный адрес','archived_at'=>'Дата архивации',
        'payment_id'=>'ID платежа','investment_lot_id'=>'Внутренний ID инвестиции','investment_transaction_id'=>'Внутренний ID операции',
        'entity_id'=>'ID объекта','user_id'=>'ID пользователя','account_id'=>'ID счёта','investment_account_id'=>'ID инвестиционного счёта','fee_rule_id'=>'ID правила комиссии',
        'deposit_request_id'=>'ID заявки на пополнение','withdrawal_request_id'=>'ID заявки на вывод','received_amount'=>'Получено','net_investment_amount'=>'Инвестиция после комиссии',
        'check_key'=>'Пункт проверки','checked_by'=>'Проверил','checked_at'=>'Дата проверки',
        'fee_payer'=>'Плательщик','fee_economic_type_snapshot'=>'Экономический тип комиссии','deposit_address_id'=>'ID адреса пополнения','deposit_address_snapshot'=>'Адрес пополнения','provider_snapshot'=>'Provider','wallet_address_snapshot'=>'Кошелёк','network_snapshot'=>'Сеть','confirmed_at'=>'Подтверждено','type'=>'Тип','name'=>'Имя','email'=>'Email','code'=>'Код инвестора','account_status'=>'Статус счёта','contract_number'=>'Номер договора','contract_date'=>'Дата договора',
    ];

    public static function actions(): array { return self::ACTIONS; }
    public static function entityTypes(): array { return self::OBJECTS; }
    public static function label(string $action): string { return self::ACTIONS[$action][0] ?? Str::headline(str_replace('.', '_', $action)); }
    public static function category(string $action): string { return self::ACTIONS[$action][1] ?? 'system'; }
    public static function categoryLabel(string $category): string { return ['financial'=>'Финансовое','administrative'=>'Административное','system'=>'Системное'][$category] ?? 'Системное'; }
    public static function object(string $type): string { return self::OBJECTS[$type] ?? class_basename($type ?: 'Системный объект'); }
    public static function field(string $key): string { return self::FIELDS[$key] ?? Str::headline($key); }

    public static function summary(AuditLog $log, ?Model $subject): string
    {
        if ($log->action === 'deposit_request.created') return 'Пополнение '.MoneyFormatter::format((string)($log->new_values['requested_amount']??'0')).' '.($log->new_values['currency']??'USDT').' · '.InvestorPresentation::status((string)($log->new_values['status']??''));
        if ($log->action === 'deposit_request.confirmed') return MoneyFormatter::format((string)($log->new_values['gross_amount']??'0')).' '.($log->new_values['currency']??'USDT').' → инвестиция '.MoneyFormatter::format((string)($log->new_values['net_investment_amount']??'0')).' '.($log->new_values['currency']??'USDT');
        if ($log->action === 'withdrawal.created') return InvestorPresentation::type(($log->new_values['type']??'') === 'capital' ? 'capital_withdrawal' : 'dividend_withdrawal').' · '.MoneyFormatter::format((string)($log->new_values['requested_amount']??'0')).' '.($log->new_values['currency']??'USDT');
        if ($log->action === 'investor.created') return ($log->new_values['name']??'Инвестор').' · '.($log->new_values['code']??'—').' · '.($log->new_values['currency']??'USDT');
        if ($subject instanceof InvestorWallet) return $subject->currency.' · '.$subject->network.' · '.WalletPresentation::shortAddress($subject->address);
        if ($subject instanceof DepositAddress) return $subject->currency.' · '.$subject->network.' · '.WalletPresentation::shortAddress($subject->address);
        if ($subject instanceof InvestmentTerm) {
            $old=$log->old_values['monthly_rate']??null; $new=$log->new_values['monthly_rate']??$subject->monthly_rate;
            $rate=$old ? self::rate((string)$old).' → '.self::rate((string)$new) : self::rate((string)$new);
            return 'Ставка '.$rate.', капитал доступен через '.InvestmentTermPresentation::lockMonths((int)($log->new_values['lock_months']??$subject->lock_months));
        }
        if ($subject instanceof FeeRule) return InvestorPresentation::type($subject->operation_type).' · '.$subject->fee_type.' · '.($subject->payer==='investor'?'инвестор':'компания');
        $old=$log->old_values['status']??null; $new=$log->new_values['status']??null;
        if ($old!==null || $new!==null) return 'Статус: '.InvestorPresentation::status($old).' → '.InvestorPresentation::status($new);
        return self::label($log->action);
    }

    public static function comparison(?array $old, ?array $new): array
    {
        $old=self::sanitize($old??[]); $new=self::sanitize($new??[]); $rows=[];
        foreach (array_unique([...array_keys($old),...array_keys($new)]) as $key) $rows[]=['key'=>$key,'label'=>self::field($key),'old'=>self::value($key,$old[$key]??null),'new'=>self::value($key,$new[$key]??null)];
        return $rows;
    }

    public static function sanitize(array $values): array
    {
        $result=[];
        foreach($values as $key=>$value){$normalized=strtolower((string)$key);$sensitive=collect(['password','remember_token','token','api_key','secret','private_key','csrf'])->contains(fn($part)=>str_contains($normalized,$part));$result[$key]=$sensitive?'••••••••':(is_array($value)?self::sanitize($value):$value);}
        return $result;
    }

    public static function value(string $key, mixed $value): string
    {
        if ($value===null || $value==='') return '—';
        if (is_array($value)) return json_encode($value, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if (in_array($key,['partial_withdrawal_allowed','is_active','is_personal'],true)) return in_array($value,[true,1,'1'],true)?'Да':'Нет';
        if ($key==='monthly_rate'||$key==='percent_value') return self::rate((string)$value);
        if ($key==='lock_months') return InvestmentTermPresentation::lockMonths((int)$value);
        if ($key==='status') return InvestorPresentation::status((string)$value);
        if (in_array($key,['minimum_balance','minimum_dividend_withdrawal','fixed_value','minimum_fee','maximum_fee','requested_amount','received_amount','reserved_amount','gross_amount','net_amount','net_investment_amount','capitalized_amount','fee','fee_amount','amount'],true)) return MoneyFormatter::format((string)$value).' USDT';
        if ($key==='investment_lot_id'||$key==='investment_transaction_id') return 'ID '.(string)$value;
        if (in_array($key,['payment_id','entity_id','user_id','account_id','investment_account_id','fee_rule_id','reversal_id'],true)) return (string)$value;
        if (is_bool($value)) return $value?'true':'false';
        return (string)$value;
    }

    private static function rate(string $rate): string { return InvestmentTermPresentation::rate($rate); }
}
