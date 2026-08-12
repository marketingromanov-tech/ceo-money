<?php

namespace App\Services;

use App\Models\DepositRequest;
use App\Models\DepositAddress;
use App\Models\InvestmentAccount;
use App\Models\InvestmentLot;
use App\Models\InvestmentProgram;
use App\Models\InvestmentTerm;
use App\Models\InvestmentTransaction;
use App\Models\InvestorPaymentDetail;
use App\Models\User;
use Carbon\Carbon;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class DepositRequestService
{
    public function __construct(
        private readonly FeeCalculatorService $fees,
        private readonly AuditLogService $audit,
        private readonly AccrualCalculator $decimal,
    )
    {
    }

    /** @return array{gross_amount:string,fee_amount:string,net_amount:string,payer:string,economic_type:?string,fee_rule_id:?int,percent_value_snapshot:string,fixed_value_snapshot:string} */
    public function preview(DepositRequest $request, string $receivedAmount): array
    {
        $receivedAmount = $this->normalizeAmount($receivedAmount);

        return $this->fees->calculate('deposit', $request->investor, $receivedAmount, $request->currency, Carbon::today());
    }

    public function paymentDetailsFor(InvestmentAccount $account, ?string $network = null): ?InvestorPaymentDetail
    {
        return $account->investor->paymentDetails()
            ->where('is_active', true)
            ->where('currency', $account->currency)
            ->when($network !== null, fn ($query) => $query->where('network', $network))
            ->latest('id')
            ->first();
    }

    public function ensurePaymentDetailsSnapshot(DepositRequest $request, User $actor): DepositRequest
    {
        return DB::transaction(function () use ($request, $actor) {
            $request=DepositRequest::query()->lockForUpdate()->findOrFail($request->id);
            if($request->investor_id!==$actor->investor?->id||$request->status!=='pending')throw new DomainException('Реквизиты этой заявки недоступны.');
            if($request->payment_details_snapshot!==null)return $request;
            $details=$this->paymentDetailsFor($request->investmentAccount,$request->network);
            if($details===null)throw new DomainException('Администратор ещё не назначил реквизиты для пополнения.');
            $request->update(['payment_details_snapshot'=>$this->paymentDetailsSnapshot($details)]);
            return $request;
        });
    }

    /**
     * Reusable preflight for the deposit verification checklist.
     *
     * @return array{date:Carbon,term:?InvestmentTerm,can_confirm:bool}
     */
    public function confirmationPreflight(DepositRequest $request, ?Carbon $date = null): array
    {
        $date = ($date ?? Carbon::today())->copy()->startOfDay();
        $term = $this->accountTerm($request, $date);

        return ['date' => $date, 'term' => $term, 'can_confirm' => $term !== null];
    }

    public function submit(DepositRequest $request, string $receivedAmount, ?string $txid, ?User $actor = null): DepositRequest
    {
        $receivedAmount = $this->normalizeAmount($receivedAmount);
        $txid = $this->normalizeTxid($txid);

        try {
            return DB::transaction(function () use ($request, $receivedAmount, $txid, $actor) {
                $request = DepositRequest::query()->lockForUpdate()->findOrFail($request->id);

                if ($request->status === 'submitted') {
                    return $request;
                }
                if (! in_array($request->status, ['pending', 'payment_submitted'], true)) {
                    throw new DomainException('Заявку в текущем статусе нельзя принять на проверку.');
                }
                $this->assertUniqueTxid($txid, $request->id);
                $fee = $this->fees->calculate('deposit', $request->investor, $receivedAmount, $request->currency, Carbon::today());
                $old = ['status' => $request->status];
                $request->update([
                    'received_amount' => $receivedAmount,
                    'txid' => $txid,
                    'fee_rule_id' => $fee['fee_rule_id'],
                    'fee_amount' => $fee['fee_amount'],
                    'fee_payer' => $fee['payer'],
                    'fee_economic_type_snapshot' => $fee['economic_type'],
                    'net_investment_amount' => $fee['net_amount'],
                    'status' => 'submitted',
                    'submitted_at' => now(),
                    'submitted_by' => ($actor ?? auth()->user())?->id,
                ]);
                $this->audit->log('deposit_request.submitted', $request, $actor ?? auth()->user(), $old, [
                    'deposit_request_id' => $request->id,
                    'investor_id' => $request->investor_id,
                    'investment_account_id' => $request->investment_account_id,
                    'status' => 'submitted',
                    'requested_amount' => $request->requested_amount,
                    'received_amount' => $request->received_amount,
                    'currency' => $request->currency,
                    'fee_amount' => $request->fee_amount,
                    'fee_payer' => $request->fee_payer,
                    'fee_economic_type_snapshot' => $request->fee_economic_type_snapshot,
                    'net_investment_amount' => $request->net_investment_amount,
                    'txid' => $request->txid,
                    'admin_id' => $request->submitted_by,
                ]);

                return $request;
            });
        } catch (QueryException $exception) {
            if ($txid !== null && DepositRequest::where('txid', $txid)->whereKeyNot($request->id)->exists()) {
                throw new DomainException('Заявка с таким TXID уже существует.', previous: $exception);
            }
            throw $exception;
        }
    }

    public function reject(DepositRequest $request, string $reason, ?User $actor = null): DepositRequest
    {
        return $this->finishWithoutCredit($request, 'rejected', $reason, $actor);
    }

    public function markPaymentSubmitted(DepositRequest $request, User $actor): DepositRequest
    {
        return DB::transaction(function () use ($request, $actor) {
            $request = DepositRequest::query()->lockForUpdate()->findOrFail($request->id);
            if ($request->status === 'payment_submitted') return $request;
            if ($request->status !== 'pending' || $request->payment_details_snapshot === null || $request->investor_id !== $actor->investor?->id) {
                throw new DomainException('Эту заявку нельзя отметить как оплаченную.');
            }
            $request->update(['status' => 'payment_submitted']);
            $this->audit->log('deposit_request.payment_submitted', $request, $actor, ['status' => 'pending'], ['deposit_request_id' => $request->id, 'investor_id' => $request->investor_id, 'status' => 'payment_submitted']);
            app(AdminNotificationService::class)->depositPaymentSubmitted($request);
            return $request;
        });
    }

    public function cancel(DepositRequest $request, string $reason, ?User $actor = null): DepositRequest
    {
        return $this->finishWithoutCredit($request, 'cancelled', $reason, $actor);
    }

    public function create(
        InvestmentAccount $account,
        string $amount,
        ?DepositAddress $depositAddress = null,
        ?string $network = null,
        ?User $actor = null,
        ?InvestmentProgram $program = null,
    ): DepositRequest {
        $programVersion = null;
        if ($program !== null) {
            app(InvestmentProgramService::class)->assertAmount($program, $amount, $account->currency);
            $programVersion = app(InvestmentProgramService::class)->activeVersion($program, Carbon::today());
            if ($programVersion === null) {
                throw new DomainException('У выбранной программы нет действующих условий.');
            }
        }
        if ($depositAddress !== null) {
            $depositAddress->refresh();

            if (! $depositAddress->is_active || $depositAddress->archived_at !== null) {
                throw new DomainException('Inactive or archived deposit address cannot be used.');
            }

            if ($depositAddress->investor_id !== $account->investor_id || ! $depositAddress->is_personal) {
                throw new DomainException('Deposit address does not belong to this investor.');
            }

            if ($depositAddress->currency !== $account->currency) {
                throw new DomainException('Deposit address currency does not match the request currency.');
            }

            if ($network !== null && $depositAddress->network !== $network) {
                throw new DomainException('Deposit address network does not match the request network.');
            }

            $network ??= $depositAddress->network;
        }

        $paymentDetails = $this->paymentDetailsFor($account, $network);

        return DB::transaction(function () use ($account, $amount, $depositAddress, $paymentDetails, $network, $actor, $program, $programVersion) {
            $request = DepositRequest::create([
                'investor_id' => $account->investor_id,
                'investment_account_id' => $account->id,
                'investment_program_id' => $program?->id,
                'investment_program_snapshot' => $program === null ? null : [
                    'program_id' => $program->id,
                    'version_id' => $programVersion->id,
                    'name' => $program->name,
                    'slug' => $program->slug,
                    'currency' => $program->currency,
                    'min_amount' => (string) $program->min_amount,
                    'max_amount' => $program->max_amount === null ? null : (string) $program->max_amount,
                    'monthly_rate' => (string) $programVersion->monthly_rate,
                    'lock_months' => $programVersion->lock_months,
                    'partial_withdrawal_allowed' => $program->is_partial_withdrawal_allowed,
                ],
                'payment_details_snapshot' => $paymentDetails === null ? null : $this->paymentDetailsSnapshot($paymentDetails),
                'requested_amount' => $amount,
                'currency' => $account->currency,
                'network' => $network,
                'deposit_address_id' => $depositAddress?->id,
                'deposit_address_snapshot' => $depositAddress?->address,
                'provider_snapshot' => $depositAddress?->provider,
                'status' => 'pending',
                'requested_at' => now(),
            ]);
            $this->audit->log('deposit_request.created', $request, $actor ?? auth()->user(), null, [
                'deposit_request_id' => $request->id, 'investor_id' => $request->investor_id,
                'investment_account_id' => $request->investment_account_id,
                'investment_program_id' => $request->investment_program_id,
                'investment_program_snapshot' => $request->investment_program_snapshot,
                'payment_details_snapshot' => $request->payment_details_snapshot,
                'requested_amount' => $request->requested_amount, 'received_amount' => $request->received_amount,
                'currency' => $request->currency, 'fee_amount' => $request->fee_amount,
                'fee_payer' => $request->fee_payer, 'fee_economic_type_snapshot' => $request->fee_economic_type_snapshot,
                'deposit_address_id' => $request->deposit_address_id,
                'deposit_address_snapshot' => $request->deposit_address_snapshot,
                'provider_snapshot' => $request->provider_snapshot, 'status' => $request->status,
            ]);
            app(AdminNotificationService::class)->depositCreated($request);

            return $request;
        });
    }

    public function confirm(
        DepositRequest $request,
        ?User $confirmedBy = null,
        ?string $monthlyRate = null,
        ?int $lockMonths = null,
        ?Carbon $accrualStartDate = null,
        ?Carbon $unlockDate = null,
        ?InvestmentProgram $program = null,
    ): DepositRequest {
        if ($request->status !== 'confirmed') {
            $verification = app(DepositVerificationService::class);
            $verification->refreshSystemChecks($request);
            if (! $verification->allRequiredPassed($request)) {
                throw new DomainException('Не завершена обязательная проверка поступления.');
            }
        }

        return DB::transaction(function () use (
            $request, $confirmedBy, $monthlyRate, $lockMonths, $accrualStartDate, $unlockDate, $program,
        ) {
            $request = DepositRequest::query()->lockForUpdate()->findOrFail($request->id);

            if ($request->status === 'confirmed') {
                return $request;
            }

            if ($request->status !== 'submitted') {
                throw new DomainException('This deposit request cannot be confirmed.');
            }

            $oldStatus = $request->status;
            $date = Carbon::today();
            $program ??= $request->investmentProgram;
            if ($request->received_amount === null || $this->decimal->compare($request->received_amount, '0') <= 0) {
                throw new DomainException('Фактически полученная сумма не указана.');
            }
            $gross = (string) $request->received_amount;
            $this->assertUniqueTxid($this->normalizeTxid($request->txid), $request->id);
            $fee = $request->net_investment_amount === null
                ? $this->fees->calculate('deposit', $request->investor, $gross, $request->currency, $date)
                : [
                    'fee_rule_id' => $request->fee_rule_id,
                    'fee_amount' => (string) $request->fee_amount,
                    'payer' => $request->fee_payer ?? 'investor',
                    'economic_type' => $request->fee_economic_type_snapshot,
                    'net_amount' => (string) $request->net_investment_amount,
                ];
            $programVersion = null;
            if ($program !== null) {
                $programs = app(InvestmentProgramService::class);
                $programs->assertAmount($program, $fee['net_amount'], $request->currency);
                $programVersion = $programs->activeVersion($program, $date)
                    ?? throw new DomainException('У выбранной программы нет действующих условий.');
                $term = $programs->termFor($program, $request->investmentAccount, $date, $confirmedBy ?? auth()->user());
            } else {
                $term = $this->confirmationPreflight($request, $date)['term'];
            }

            if ($term !== null) {
                $monthlyRate = $term->monthly_rate;
                $lockMonths = $term->lock_months;
                $accrualStartDate ??= $date->copy();
            } elseif ($monthlyRate === null || $lockMonths === null || $accrualStartDate === null) {
                throw new DomainException('Explicit lot terms are required when no account term is active.');
            }

            $unlockDate ??= $lockMonths > 0 ? $accrualStartDate->copy()->addMonthsNoOverflow($lockMonths) : null;
            $lot = InvestmentLot::create([
                'investment_account_id' => $request->investment_account_id,
                'original_amount' => $fee['net_amount'],
                'remaining_amount' => $fee['net_amount'],
                'currency' => $request->currency,
                'received_at' => now(),
                'accrual_start_date' => $accrualStartDate,
                'lock_months' => $lockMonths,
                'unlock_date' => $unlockDate,
                'monthly_rate' => $monthlyRate,
                'status' => 'active',
            ]);
            if ($program !== null && $programVersion !== null) {
                app(InvestmentProgramService::class)->snapshot($lot, $program, $programVersion);
            }

            $transaction = InvestmentTransaction::create([
                'investment_account_id' => $request->investment_account_id,
                'investment_lot_id' => $lot->id,
                'type' => 'deposit',
                'amount' => $fee['net_amount'],
                'currency' => $request->currency,
                'effective_date' => $date,
                'status' => 'confirmed',
                'created_by' => $confirmedBy?->id,
                'confirmed_by' => $confirmedBy?->id,
                'confirmed_at' => now(),
            ]);

            $request->update([
                'fee_rule_id' => $fee['fee_rule_id'],
                'fee_amount' => $fee['fee_amount'],
                'fee_payer' => $fee['payer'],
                'fee_economic_type_snapshot' => $fee['economic_type'],
                'net_investment_amount' => $fee['net_amount'],
                'status' => 'confirmed',
                'confirmed_at' => now(),
                'confirmed_by' => $confirmedBy?->id,
            ]);
            $this->audit->log('deposit_request.confirmed', $request, $confirmedBy ?? auth()->user(), [
                'status' => $oldStatus,
            ], [
                'deposit_request_id' => $request->id, 'investor_id' => $request->investor_id,
                'investment_account_id' => $request->investment_account_id, 'status' => 'confirmed',
                'currency' => $request->currency, 'gross_amount' => $gross, 'fee_amount' => $fee['fee_amount'],
                'fee_payer' => $fee['payer'], 'fee_economic_type_snapshot' => $fee['economic_type'],
                'net_investment_amount' => $fee['net_amount'], 'investment_lot_id' => $lot->id,
                'investment_transaction_id' => $transaction->id, 'confirmed_at' => $request->confirmed_at?->toDateTimeString(),
            ]);

            return $request;
        });
    }

    private function finishWithoutCredit(DepositRequest $request, string $status, string $reason, ?User $actor): DepositRequest
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 2000) {
            throw new DomainException('Укажите причину длиной не более 2000 символов.');
        }

        return DB::transaction(function () use ($request, $status, $reason, $actor) {
            $request = DepositRequest::query()->lockForUpdate()->findOrFail($request->id);
            $resolvedActor = $actor ?? auth()->user();
            if ($request->status === $status) {
                return $request;
            }
            if (! in_array($request->status, ['pending', 'payment_submitted', 'submitted'], true)) {
                throw new DomainException('Заявку в текущем статусе нельзя изменить.');
            }
            $old = ['status' => $request->status];
            $values = $status === 'rejected'
                ? ['status' => $status, 'rejected_at' => now(), 'rejected_by' => $resolvedActor?->id, 'rejected_reason' => $reason]
                : ['status' => $status, 'cancelled_at' => now(), 'cancelled_by' => $resolvedActor?->id, 'cancellation_reason' => $reason];
            $request->update($values);
            $this->audit->log('deposit_request.'.$status, $request, $resolvedActor, $old, [
                'deposit_request_id' => $request->id,
                'investor_id' => $request->investor_id,
                'investment_account_id' => $request->investment_account_id,
                'status' => $status,
                'reason' => $reason,
            ]);

            return $request;
        });
    }

    private function normalizeAmount(string $amount): string
    {
        $amount = trim($amount);
        if (! preg_match('/^\d+(?:\.\d{1,8})?$/', $amount) || $this->decimal->compare($amount, '0') <= 0 || $this->decimal->compare($amount, '9999999999.99999999') > 0) {
            throw new DomainException('Фактически полученная сумма должна быть положительным числом с точностью до 8 знаков.');
        }

        return $this->decimal->add($amount, '0');
    }

    private function paymentDetailsSnapshot(InvestorPaymentDetail $details): array
    {
        return ['payment_detail_id'=>$details->id,'currency'=>$details->currency,'network'=>$details->network,'address'=>$details->address,'memo'=>$details->memo];
    }

    private function normalizeTxid(?string $txid): ?string
    {
        $txid = $txid === null ? null : mb_strtolower(trim($txid));
        if ($txid === '') {
            return null;
        }
        if (mb_strlen($txid) > 255) {
            throw new DomainException('TXID не должен превышать 255 символов.');
        }

        return $txid;
    }

    private function assertUniqueTxid(?string $txid, int $exceptId): void
    {
        if ($txid !== null && DepositRequest::where('txid', $txid)->whereKeyNot($exceptId)->exists()) {
            throw new DomainException('Заявка с таким TXID уже существует.');
        }
    }

    private function accountTerm(DepositRequest $request, Carbon $date): ?InvestmentTerm
    {
        return InvestmentTerm::query()
            ->where('investment_account_id', $request->investment_account_id)
            ->whereNull('investment_lot_id')
            ->whereDate('valid_from', '<=', $date)
            ->where(fn ($query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $date))
            ->latest('valid_from')->latest('id')->first();
    }
}
