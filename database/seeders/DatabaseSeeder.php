<?php

namespace Database\Seeders;

use App\Models\DepositRequest;
use App\Models\FeeRule;
use App\Models\InvestmentAccount;
use App\Models\InvestmentLot;
use App\Models\InvestmentProgram;
use App\Models\InvestmentProgramVersion;
use App\Models\InvestmentTerm;
use App\Models\InvestmentTransaction;
use App\Models\Investor;
use App\Models\InvestorWallet;
use App\Models\InvestorWithdrawalDetail;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Models\SupportTicket;
use App\Services\DailyAccrualService;
use App\Services\CapitalWithdrawalService;
use App\Services\DepositRequestService;
use App\Services\DepositVerificationService;
use App\Services\DividendCapitalizationService;
use App\Services\DividendWithdrawalService;
use App\Services\WithdrawalRequestService;
use App\Services\WithdrawalWorkflowService;
use App\Services\WithdrawalVerificationService;
use App\Services\SupportTicketService;
use Carbon\Carbon;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $today = Carbon::today();
        $admin = User::updateOrCreate(['email' => 'admin@example.com'], [
            'name' => 'CEO Money Admin', 'password' => Hash::make('password'),
            'role' => 'admin', 'is_active' => true,
        ]);
        $this->createFeeRules($admin, $today);

        // Existing demo investor is preserved and receives a consistent recent accrual history.
        [, $demoAccount] = $this->createInvestor(
            'Demo Investor', 'investor@example.com', 'INV-DEMO', 'DEMO-001', $today->copy()->subMonths(6),
        );
        InvestorWithdrawalDetail::updateOrCreate([
            'investor_id' => $demoAccount->investor_id, 'currency' => 'USDT', 'network' => 'TRC20',
        ], ['address' => 'TDemoInvestorWallet001', 'memo' => null, 'is_active' => true]);
        $demoLot = $this->createLot(
            $demoAccount, '10000.00000000', '3.0000', 6,
            $today->copy()->subMonths(3), $today->copy()->addMonths(3),
        );
        $this->createDepositTransaction($demoLot, $admin);
        $this->accrue($demoLot, $today->copy()->subDays(44), $today);

        // Investor A: mature unlocked position, long accrual history and a paid dividend.
        [$alexey, $alexeyAccount] = $this->createInvestor(
            'Алексей Смирнов', 'alexey@example.com', 'INV-002', 'CM-002', $today->copy()->subMonths(7),
            '+7 900 100-20-30',
        );
        $this->createPersonalDividendRule($alexey, $admin, $today);
        InvestorWithdrawalDetail::updateOrCreate([
            'investor_id' => $alexey->id, 'currency' => 'USDT', 'network' => 'TRC20',
        ], ['address' => 'TAlexeyDemoWallet001', 'memo' => 'INV-002', 'is_active' => true]);
        $alexeyStart = $today->copy()->subMonths(5)->startOfDay();
        $alexeyLot = $this->createLot(
            $alexeyAccount, '25000.00000000', '2.5000', 3,
            $alexeyStart, $alexeyStart->copy()->addMonthsNoOverflow(3),
        );
        $this->createDepositTransaction($alexeyLot, $admin);
        $this->accrue($alexeyLot, $alexeyStart, $today);

        $alexeyWithdrawal = WithdrawalRequest::firstOrCreate([
            'investor_id' => $alexey->id,
            'investment_account_id' => $alexeyAccount->id,
            'type' => 'dividend',
            'requested_amount' => '1200.00000000',
        ], [
            'reserved_amount' => '1200.00000000', 'fee_amount' => '12.00000000',
            'fee_payer' => 'investor', 'net_amount' => '1188.00000000', 'currency' => 'USDT',
            'wallet_address_snapshot' => 'TAlexeyDemoWallet001', 'network_snapshot' => 'TRC20',
            'status' => 'approved', 'requested_at' => $today->copy()->subDays(24),
            'approved_at' => $today->copy()->subDays(22), 'approved_by' => $admin->id,
        ]);
        $this->completeWithdrawalVerification($alexeyWithdrawal, $admin);
        $alexeyPayment = app(DividendWithdrawalService::class)->pay($alexeyWithdrawal, $admin, 'demo-dividend-alexey-001');
        $alexeyPaidAt = $today->copy()->subDays(20)->setTime(14, 30);
        $alexeyPayment->update(['paid_at' => $alexeyPaidAt]);
        $alexeyWithdrawal->update(['paid_at' => $alexeyPaidAt]);

        InvestmentTerm::updateOrCreate([
            'investment_account_id' => $alexeyAccount->id,
            'investment_lot_id' => null,
            'valid_from' => $today->copy()->subMonths(7)->toDateString(),
        ], [
            'monthly_rate' => '2.5000', 'lock_months' => 3,
            'minimum_balance' => '0.00000000', 'partial_withdrawal_allowed' => true,
            'minimum_dividend_withdrawal' => '0.00000000', 'created_by' => $admin->id,
        ]);
        app(DividendCapitalizationService::class)->capitalize(
            $alexeyAccount, '400.00000000', $admin,
        );
        $this->createCompletedFeeDemoOperations($alexeyAccount, $demoAccount, $admin);
        InvestorWallet::updateOrCreate([
            'investor_id' => $alexey->id, 'network' => 'TRC20', 'address' => 'TAlexeyApprovedWallet001',
        ], [
            'currency' => 'USDT', 'label' => 'Основной кошелёк', 'status' => 'approved',
            'approved_by' => $admin->id, 'approved_at' => now(),
        ]);
        $this->createWithdrawalLifecycleDemos($alexeyAccount, $admin);

        // Investor B: two deposits with different start and unlock dates.
        [$maria, $mariaAccount] = $this->createInvestor(
            'Мария Волкова', 'maria@example.com', 'INV-003', 'CM-003', $today->copy()->subMonths(6),
            '+7 900 200-30-40',
        );
        $mariaFirstStart = $today->copy()->subMonths(4)->startOfDay();
        $mariaFirstLot = $this->createLot(
            $mariaAccount, '25000.00000000', '3.0000', 2,
            $mariaFirstStart, $mariaFirstStart->copy()->addMonthsNoOverflow(2),
        );
        $mariaSecondStart = $today->copy()->subDays(42)->startOfDay();
        $mariaSecondLot = $this->createLot(
            $mariaAccount, '15000.00000000', '3.0000', 6,
            $mariaSecondStart, $mariaSecondStart->copy()->addMonthsNoOverflow(6),
        );
        $this->createDepositTransaction($mariaFirstLot, $admin);
        $this->createDepositTransaction($mariaSecondLot, $admin);
        $this->accrue($mariaFirstLot, $mariaFirstStart, $today);
        $this->accrue($mariaSecondLot, $mariaSecondStart, $today);

        if (! DepositRequest::where('txid', 'demo-maria-submitted-001')->exists()) {
            $request = app(DepositRequestService::class)->create($mariaAccount, '5000.00000000', network: 'TRC20', actor: $admin);
            $request->update(['deposit_address_snapshot' => 'TMariaDepositDemo001', 'provider_snapshot' => 'manual']);
            app(DepositRequestService::class)->submit($request, '5000.00000000', 'demo-maria-submitted-001', $admin);
            $verification=app(DepositVerificationService::class);$verification->initializeForRequest($request);foreach(['provider_opened','currency_verified','network_verified','address_verified','incoming_transaction_found'] as $key)$verification->markPassed($request,$key,$admin);
        }
        DepositRequest::firstOrCreate([
            'investor_id' => $maria->id, 'investment_account_id' => $mariaAccount->id,
            'requested_amount' => '2750.00000000', 'status' => 'pending',
        ], [
            'currency' => 'USDT', 'network' => 'TRC20',
            'deposit_address_snapshot' => 'TMariaDepositDemo001', 'provider_snapshot' => 'BingX',
            'requested_at' => $today->copy()->subHours(3),
        ]);

        // Investor C: recent locked position and a dividend request awaiting review.
        [$dmitry, $dmitryAccount] = $this->createInvestor(
            'Дмитрий Орлов', 'dmitry@example.com', 'INV-004', 'CM-004', $today->copy()->startOfMonth(),
            '+7 900 300-40-50',
        );
        $dmitryStart = $today->copy()->subDays(min(8, max(1, $today->day - 1)))->startOfDay();
        $dmitryLot = $this->createLot(
            $dmitryAccount, '7500.00000000', '3.5000', 4,
            $dmitryStart, $dmitryStart->copy()->addMonthsNoOverflow(4),
        );
        $this->createDepositTransaction($dmitryLot, $admin);
        $this->accrue($dmitryLot, $dmitryStart, $today);

        if (! DepositRequest::where('txid', 'demo-dmitry-rejected-001')->exists()) {
            $request = app(DepositRequestService::class)->create($dmitryAccount, '900.00000000', network: 'TRC20', actor: $dmitry->user);
            $request->update(['deposit_address_snapshot' => 'TDmitryDepositDemo001', 'provider_snapshot' => 'BingX']);
            app(DepositRequestService::class)->submit($request, '900.00000000', 'demo-dmitry-rejected-001', $admin);
            app(DepositRequestService::class)->reject($request->fresh(), 'Платёж по указанному TXID не найден.', $admin);
        }
        if (! DepositRequest::where('cancellation_reason', 'Демо: заявка отменена администратором.')->exists()) {
            $request = app(DepositRequestService::class)->create($dmitryAccount, '350.00000000', network: 'TRC20', actor: $dmitry->user);
            app(DepositRequestService::class)->cancel($request, 'Демо: заявка отменена администратором.', $admin);
        }

        $dmitryReview = WithdrawalRequest::updateOrCreate([
            'investment_account_id' => $dmitryAccount->id,
            'type' => 'dividend',
            'wallet_address_snapshot' => 'TDmitryDemoWallet001',
        ], [
            'investor_id' => $dmitry->id, 'requested_amount' => '15.00000000',
            'reserved_amount' => '15.00000000', 'fee_amount' => '0.15000000',
            'fee_payer' => 'investor', 'net_amount' => '14.85000000', 'currency' => 'USDT',
            'network_snapshot' => 'TRC20', 'status' => 'review',
            'requested_at' => $today->copy()->subDay()->setTime(16, 20),
        ]);
        $withdrawalVerification=app(WithdrawalVerificationService::class);$withdrawalVerification->initializeForRequest($dmitryReview);
        foreach(['investor_identity_verified','withdrawal_reason_checked','wallet_address_verified'] as $key){if($dmitryReview->verificationChecks()->where('check_key',$key)->value('status')!=='passed')$withdrawalVerification->markPassed($dmitryReview,$key,$admin);}
        $this->createSupportDemo($alexey, $maria, $dmitry, $admin);
        $this->createInvestmentPrograms($admin, $today);
    }

    private function createInvestmentPrograms(User $admin, Carbon $today): void
    {
        $definitions = [
            ['Start', 'start', 'Начальная программа', '1000', '2499', '2.0000', 3],
            ['Standard', 'standard', 'Стандартная программа', '2500', '4999', '2.5000', 3],
            ['Advanced', 'advanced', 'Расширенная программа', '5000', '9999', '3.0000', 6],
            ['Premium', 'premium', 'Премиальная программа', '10000', '24999', '3.5000', 6],
            ['VIP', 'vip', 'Индивидуальная программа для крупного капитала', '25000', null, '4.0000', 12],
        ];
        $programs = collect();
        foreach ($definitions as [$name, $slug, $description, $min, $max, $rate, $months]) {
            $program = InvestmentProgram::updateOrCreate(['slug' => $slug], ['name' => $name, 'description' => $description, 'status' => 'active', 'currency' => 'USDT', 'min_amount' => $min, 'max_amount' => $max, 'is_partial_withdrawal_allowed' => true, 'created_by' => $admin->id]);
            $version = InvestmentProgramVersion::updateOrCreate(['investment_program_id' => $program->id, 'valid_from' => $today->copy()->subYear()->toDateString()], ['monthly_rate' => $rate, 'lock_months' => $months, 'valid_to' => null, 'created_by' => $admin->id]);
            $programs->put($slug, [$program, $version]);
        }
        $distribution = ['start', 'standard', 'standard', 'advanced', 'advanced', 'premium', 'vip'];
        foreach (InvestmentLot::with('investmentAccount')->where('remaining_amount', '>', 0)->orderBy('received_at')->orderBy('id')->limit(7)->get()->values() as $index => $lot) {
            [$program, $version] = $programs[$distribution[$index]];
            $lot->update(['investment_program_id' => $program->id, 'investment_program_name_snapshot' => $program->name, 'investment_program_version_snapshot' => 'v1']);
        }
    }

    private function createSupportDemo(Investor $alexey, Investor $maria, Investor $dmitry, User $admin): void
    {
        $service=app(SupportTicketService::class);
        if(!SupportTicket::where('investor_id',$alexey->id)->where('subject','Вопрос по начислениям')->exists()){$t=$service->create($alexey,$alexey->user,'accruals','Вопрос по начислениям','Подскажите, как рассчитывается ежедневное начисление?');$service->changeStatus($t,$admin,'open');$service->reply($t,$admin,'Начисление рассчитывается ежедневно по условиям вашей инвестиции.');$service->addInternalNote($t,$admin,'Проверены начисления за текущий месяц — расхождений нет.');}
        if(!SupportTicket::where('investor_id',$maria->id)->where('subject','Когда будет обработано пополнение?')->exists()){$deposit=DepositRequest::where('investor_id',$maria->id)->where('status','submitted')->first();$t=$service->create($maria,$maria->user,'deposit','Когда будет обработано пополнение?','Пополнение отправлено, подскажите срок проверки.','normal',$deposit?'deposit_request':null,$deposit?->id);$service->changeStatus($t,$admin,'open');$service->changeStatus($t,$admin,'waiting_investor');$service->reply($t,$admin,'Пожалуйста, уточните TXID операции.');}
        if(!SupportTicket::where('investor_id',$dmitry->id)->where('subject','Хочу изменить кошелёк')->exists())$service->create($dmitry,$dmitry->user,'wallet','Хочу изменить кошелёк','Как заменить адрес кошелька для вывода?','high');
    }

    private function createFeeRules(User $admin, Carbon $today): void
    {
        $validFrom = $today->copy()->subYear()->toDateString();
        foreach ([
            ['dividend_withdrawal', 'percent', '0.00000000', '1.0000', 'platform_fee', 'investor'],
            ['capital_withdrawal', 'mixed', '2.00000000', '0.5000', 'platform_fee', 'investor'],
            ['capitalization', 'percent', '0.00000000', '0.5000', 'platform_fee', 'investor'],
            ['deposit', 'fixed', '1.00000000', '0.0000', 'provider_cost', 'company'],
        ] as [$operation, $feeType, $fixed, $percent, $economicType, $payer]) {
            FeeRule::updateOrCreate([
                'operation_type' => $operation, 'scope' => 'global', 'investor_id' => null,
                'currency' => 'USDT', 'valid_from' => $validFrom,
            ], [
                'fee_type' => $feeType, 'fixed_value' => $fixed, 'percent_value' => $percent,
                'payer' => $payer, 'economic_type' => $economicType, 'is_active' => true,
                'created_by' => $admin->id,
            ]);
        }
    }

    private function createPersonalDividendRule(Investor $investor, User $admin, Carbon $today): void
    {
        FeeRule::updateOrCreate([
            'operation_type' => 'dividend_withdrawal', 'scope' => 'investor',
            'investor_id' => $investor->id, 'currency' => 'USDT',
            'valid_from' => $today->copy()->subYear()->toDateString(),
        ], [
            'fee_type' => 'percent', 'fixed_value' => '0.00000000', 'percent_value' => '0.7500',
            'payer' => 'investor', 'economic_type' => 'platform_fee', 'is_active' => true,
            'created_by' => $admin->id,
        ]);
    }

    private function createCompletedFeeDemoOperations(
        InvestmentAccount $alexeyAccount,
        InvestmentAccount $globalAccount,
        User $admin,
    ): void {
        $workflow = app(WithdrawalWorkflowService::class);

        if (! WithdrawalRequest::where('txid', 'demo-personal-dividend-fee')->exists()) {
            $request = app(WithdrawalRequestService::class)->createDividendRequest($alexeyAccount, '100.00000000', 'TAlexeyDemoWallet001', 'TRC20', actor: $admin);
            $workflow->moveToReview($request, $admin);
            $workflow->approve($request, $admin);
            $this->completeWithdrawalVerification($request, $admin);
            app(DividendWithdrawalService::class)->pay($request, $admin, 'demo-personal-dividend-fee');
        }

        if (! WithdrawalRequest::where('txid', 'demo-global-dividend-fee')->exists()) {
            $request = app(WithdrawalRequestService::class)->createDividendRequest($globalAccount, '100.00000000', 'TDemoInvestorWallet001', 'TRC20', actor: $admin);
            $workflow->moveToReview($request, $admin);
            $workflow->approve($request, $admin);
            $this->completeWithdrawalVerification($request, $admin);
            app(DividendWithdrawalService::class)->pay($request, $admin, 'demo-global-dividend-fee');
        }

        if (! WithdrawalRequest::where('txid', 'demo-capital-fee')->exists()) {
            $request = app(WithdrawalRequestService::class)->createCapitalRequest($alexeyAccount, '1000.00000000', 'TAlexeyDemoWallet001', 'TRC20', actor: $admin);
            $workflow->moveToReview($request, $admin);
            $workflow->approve($request, $admin);
            $this->completeWithdrawalVerification($request, $admin);
            app(CapitalWithdrawalService::class)->pay($request, $admin, 'demo-capital-fee');
        }

        if (! DepositRequest::where('txid', 'demo-provider-cost-deposit')->exists()) {
            $request = app(DepositRequestService::class)->create($alexeyAccount, '1000.00000000', actor: $admin);
            app(DepositRequestService::class)->submit($request, '1000.00000000', 'demo-provider-cost-deposit', $admin);
            $verification=app(DepositVerificationService::class);$verification->initializeForRequest($request);foreach(DepositVerificationService::MANUAL_KEYS as $key)$verification->markPassed($request,$key,$admin);
            app(DepositRequestService::class)->confirm($request, $admin);
        }
        if (! DepositRequest::where('txid', 'demo-ready-deposit')->exists()) {
            $request=app(DepositRequestService::class)->create($alexeyAccount,'250.00000000',actor:$admin);
            app(DepositRequestService::class)->submit($request,'250.00000000','demo-ready-deposit',$admin);
            $verification=app(DepositVerificationService::class);$verification->initializeForRequest($request);foreach(DepositVerificationService::MANUAL_KEYS as $key)$verification->markPassed($request,$key,$admin);
        }
    }

    private function createWithdrawalLifecycleDemos(InvestmentAccount $account, User $admin): void
    {
        $requests=app(WithdrawalRequestService::class);$workflow=app(WithdrawalWorkflowService::class);$verification=app(WithdrawalVerificationService::class);$wallet=$account->investor->wallets()->where('status','approved')->first();
        $make=function(string $address,string $amount)use($account,$admin,$requests,$wallet):WithdrawalRequest{return WithdrawalRequest::where('investment_account_id',$account->id)->where('investor_wallet_id',$wallet?->id)->where('requested_amount',$amount)->first()??$requests->createDividendRequest($account,$amount,$address,'TRC20',$wallet,actor:$admin);};

        $make('TDemoWithdrawalNew001','25.00000000');
        $approved=$make('TDemoWithdrawalApproved001','30.00000000');
        if($approved->status==='new')$workflow->moveToReview($approved,$admin);
        if($approved->fresh()->status==='review')$workflow->approve($approved->fresh(),$admin);
        $verification->initializeForRequest($approved->fresh());
        foreach(WithdrawalVerificationService::MANUAL_KEYS as $key){$check=$approved->verificationChecks()->where('check_key',$key)->first();if($check?->status!=='passed')$verification->markPassed($approved->fresh(),$key,$admin);}

        $rejected=$make('TDemoWithdrawalRejected001','20.00000000');
        if($rejected->status==='new'){$workflow->moveToReview($rejected,$admin);$workflow->reject($rejected->fresh(),$admin,'Реквизиты кошелька не прошли проверку.');}
        $cancelled=$make('TDemoWithdrawalCancelled001','15.00000000');
        if($cancelled->status==='new')$workflow->cancel($cancelled,$admin,'Операция отменена администратором.');
    }

    private function createInvestor(
        string $name,
        string $email,
        string $code,
        string $contractNumber,
        Carbon $contractDate,
        ?string $phone = null,
    ): array {
        $user = User::updateOrCreate(['email' => $email], [
            'name' => $name, 'password' => Hash::make('password'),
            'role' => 'investor', 'is_active' => true,
        ]);
        $investor = Investor::updateOrCreate(['user_id' => $user->id], [
            'code' => $code, 'phone' => $phone, 'contract_number' => $contractNumber,
            'contract_date' => $contractDate->toDateString(), 'status' => 'active',
        ]);
        $account = InvestmentAccount::updateOrCreate(['investor_id' => $investor->id], [
            'currency' => 'USDT', 'status' => 'active', 'opened_at' => $contractDate,
        ]);

        return [$investor, $account];
    }

    private function createLot(
        InvestmentAccount $account,
        string $amount,
        string $monthlyRate,
        int $lockMonths,
        Carbon $startDate,
        Carbon $unlockDate,
    ): InvestmentLot {
        return InvestmentLot::updateOrCreate([
            'investment_account_id' => $account->id,
            'received_at' => $startDate,
            'original_amount' => $amount,
        ], [
            'remaining_amount' => $amount, 'currency' => 'USDT',
            'accrual_start_date' => $startDate->toDateString(), 'lock_months' => $lockMonths,
            'unlock_date' => $unlockDate->toDateString(), 'monthly_rate' => $monthlyRate,
            'status' => 'active',
        ]);
    }

    private function createDepositTransaction(InvestmentLot $lot, User $admin): void
    {
        InvestmentTransaction::firstOrCreate([
            'investment_account_id' => $lot->investment_account_id,
            'investment_lot_id' => $lot->id,
            'type' => 'deposit',
        ], [
            'amount' => $lot->original_amount, 'currency' => $lot->currency,
            'effective_date' => $lot->received_at->toDateString(), 'status' => 'confirmed',
            'comment' => 'Demo deposit for investment lot #'.$lot->id,
            'created_by' => $admin->id, 'confirmed_by' => $admin->id,
            'confirmed_at' => $lot->received_at,
        ]);
    }

    private function accrue(InvestmentLot $lot, Carbon $from, Carbon $to): void
    {
        app(DailyAccrualService::class)->recalculateForLot($lot, $from, $to);
    }

    private function completeWithdrawalVerification(WithdrawalRequest $request, User $admin): void
    {
        $request->refresh();
        $verification = app(WithdrawalVerificationService::class);
        $verification->initializeForRequest($request);
        foreach (WithdrawalVerificationService::MANUAL_KEYS as $key) {
            $verification->markPassed($request, $key, $admin);
        }
    }
}
