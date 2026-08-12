<?php

namespace App\Services;

use App\Models\InvestmentAccount;
use App\Models\InvestmentTerm;
use App\Models\InvestorWallet;
use App\Models\WithdrawalRequest;
use App\Models\User;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\DB;

class WithdrawalRequestService
{
    public function __construct(
        private readonly AvailableBalanceService $balances,
        private readonly FeeCalculatorService $fees,
        private readonly AccrualCalculator $decimal,
        private readonly InvestorWithdrawalDetailService $withdrawalDetails,
    ) {
    }

    public function createDividendRequest(
        InvestmentAccount $account,
        string $amount,
        ?string $walletAddress = null,
        ?string $network = null,
        ?InvestorWallet $investorWallet = null,
        ?User $actor = null,
    ): WithdrawalRequest {
        return DB::transaction(function () use ($account, $amount, $walletAddress, $network, $investorWallet, $actor) {
            $account = InvestmentAccount::query()->lockForUpdate()->findOrFail($account->id);
            $date = Carbon::today();
            $this->assertPositiveAndAvailable($amount, $this->balances->availableDividendBalance($account));
            $term = $this->accountTerm($account, $date);

            if ($term !== null && $this->decimal->compare($amount, $term->minimum_dividend_withdrawal) < 0) {
                throw new DomainException('Amount is below the minimum dividend withdrawal.');
            }

            return $this->create(
                $account, 'dividend', 'dividend_withdrawal', $amount,
                $walletAddress, $network, $date, $investorWallet, $actor,
            );
        });
    }

    public function createCapitalRequest(
        InvestmentAccount $account,
        string $amount,
        ?string $walletAddress = null,
        ?string $network = null,
        ?Carbon $date = null,
        ?InvestorWallet $investorWallet = null,
        ?User $actor = null,
    ): WithdrawalRequest {
        $date ??= Carbon::today();
        $available = $this->balances->availableCapitalForWithdrawal($account, $date);
        $this->assertPositiveAndAvailable($amount, $available);
        $term = $this->accountTerm($account, $date);

        if ($term !== null) {
            if (! $term->partial_withdrawal_allowed && $this->decimal->compare($amount, $available) !== 0) {
                throw new DomainException('Partial capital withdrawal is not allowed.');
            }

            $remaining = $this->decimal->subtract($available, $amount);

            if ($this->decimal->compare($remaining, $term->minimum_balance) < 0) {
                throw new DomainException('Minimum balance requirement would be violated.');
            }
        }

        return $this->create($account, 'capital', 'capital_withdrawal', $amount, $walletAddress, $network, $date, $investorWallet, $actor);
    }

    private function create(
        InvestmentAccount $account,
        string $type,
        string $operationType,
        string $amount,
        ?string $walletAddress,
        ?string $network,
        Carbon $date,
        ?InvestorWallet $investorWallet,
        ?User $actor,
    ): WithdrawalRequest {
        $memo = null;
        if ($investorWallet !== null) {
            $investorWallet->refresh();

            if ($investorWallet->investor_id !== $account->investor_id) {
                throw new DomainException('Investor wallet does not belong to this account investor.');
            }

            if ($investorWallet->status !== 'approved') {
                throw new DomainException('Only an approved investor wallet can be used.');
            }

            if ($investorWallet->currency !== $account->currency) {
                throw new DomainException('Investor wallet currency does not match the withdrawal currency.');
            }

            if ($network !== null && $investorWallet->network !== $network) {
                throw new DomainException('Investor wallet network does not match the withdrawal network.');
            }

            $walletAddress = $investorWallet->address;
            $network = $investorWallet->network;
        } else {
            $detail = $this->withdrawalDetails->latestActiveFor($account, $network);

            if ($detail === null) {
                throw new DomainException('No active investor withdrawal details are assigned.');
            }

            $walletAddress = $detail->address;
            $network = $detail->network;
            $memo = $detail->memo;
        }

        $fee = $this->fees->calculate($operationType, $account->investor, $amount, $account->currency, $date);

        return DB::transaction(function () use ($account, $type, $amount, $fee, $walletAddress, $network, $memo, $investorWallet, $actor) {
        $request = WithdrawalRequest::create([
            'investor_id' => $account->investor_id,
            'investment_account_id' => $account->id,
            'type' => $type,
            'requested_amount' => $fee['gross_amount'],
            'reserved_amount' => $fee['gross_amount'],
            'fee_rule_id' => $fee['fee_rule_id'],
            'fee_amount' => $fee['fee_amount'],
            'fee_payer' => $fee['payer'],
            'fee_economic_type_snapshot' => $fee['economic_type'],
            'net_amount' => $fee['net_amount'],
            'currency' => $account->currency,
            'investor_wallet_id' => $investorWallet?->id,
            'wallet_address_snapshot' => $walletAddress,
            'network_snapshot' => $network,
            'withdrawal_memo_snapshot' => $memo,
            'status' => 'new',
            'requested_at' => now(),
        ]);
        app(AuditLogService::class)->log('withdrawal.created', $request, $actor ?? auth()->user(), null, [
            'withdrawal_request_id' => $request->id, 'investor_id' => $request->investor_id,
            'investment_account_id' => $request->investment_account_id, 'type' => $request->type,
            'requested_amount' => $request->requested_amount, 'fee_amount' => $request->fee_amount,
            'fee_payer' => $request->fee_payer, 'fee_economic_type_snapshot' => $request->fee_economic_type_snapshot,
            'net_amount' => $request->net_amount, 'wallet_address_snapshot' => $request->wallet_address_snapshot,
            'network_snapshot' => $request->network_snapshot, 'withdrawal_memo_snapshot' => $request->withdrawal_memo_snapshot,
            'status' => $request->status,
        ]);
        app(AdminNotificationService::class)->withdrawalCreated($request);

        return $request;
        });
    }

    private function accountTerm(InvestmentAccount $account, Carbon $date): ?InvestmentTerm
    {
        return $account->investmentTerms()
            ->whereNull('investment_lot_id')
            ->whereDate('valid_from', '<=', $date)
            ->where(fn ($query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $date))
            ->latest('valid_from')->latest('id')->first();
    }

    private function assertPositiveAndAvailable(string $amount, string $available): void
    {
        if ($this->decimal->compare($amount, '0') <= 0) {
            throw new DomainException('Withdrawal amount must be greater than zero.');
        }

        if ($this->decimal->compare($amount, $available) > 0) {
            throw new DomainException('Withdrawal amount exceeds available balance.');
        }
    }
}
