<?php

namespace App\Services;

use App\Models\InvestmentAccount;
use App\Models\Investor;
use App\Models\InvestorWallet;
use App\Models\InvestorWithdrawalDetail;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class InvestorWithdrawalDetailService
{
    /** @return array{detail: InvestorWithdrawalDetail, outcome: 'created'|'reactivated'|'exists'} */
    public function useApprovedWallet(InvestorWallet $wallet, Investor $investor, User $admin): array
    {
        return DB::transaction(function () use ($wallet, $investor, $admin) {
            if ($admin->role !== 'admin') {
                throw new DomainException('Only an administrator can assign withdrawal details.');
            }

            $wallet = InvestorWallet::query()->lockForUpdate()->findOrFail($wallet->id);

            if ($wallet->investor_id !== $investor->id) {
                throw new DomainException('Investor wallet does not belong to this investor.');
            }

            if ($wallet->status !== 'approved') {
                throw new DomainException('Only an approved investor wallet can be used for withdrawals.');
            }

            $detail = InvestorWithdrawalDetail::query()
                ->where('investor_id', $investor->id)
                ->where('currency', $wallet->currency)
                ->where('network', $wallet->network)
                ->where('address', $wallet->address)
                ->lockForUpdate()
                ->first();

            if ($detail?->is_active) {
                return ['detail' => $detail, 'outcome' => 'exists'];
            }

            if ($detail !== null) {
                $detail->update(['is_active' => true]);
                return ['detail' => $detail->fresh(), 'outcome' => 'reactivated'];
            }

            return [
                'detail' => InvestorWithdrawalDetail::create([
                    'investor_id' => $investor->id,
                    'currency' => $wallet->currency,
                    'network' => $wallet->network,
                    'address' => $wallet->address,
                    'memo' => null,
                    'is_active' => true,
                ]),
                'outcome' => 'created',
            ];
        });
    }

    public function latestActiveFor(
        InvestmentAccount $account,
        ?string $network = null,
    ): ?InvestorWithdrawalDetail {
        return $account->investor->withdrawalDetails()
            ->where('is_active', true)
            ->where('currency', mb_strtoupper($account->currency))
            ->when(
                filled($network),
                fn ($query) => $query->where('network', trim((string) $network)),
            )
            ->latest('id')
            ->first();
    }
}
