<?php

namespace App\Services;

use App\Models\DepositAddress;
use App\Models\Investor;
use App\Models\InvestorWallet;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class WalletService
{
    public function __construct(private readonly AuditLogService $audit)
    {
    }

    public function assignDepositAddress(
        DepositAddress $address,
        Investor $investor,
        ?User $admin = null,
    ): DepositAddress {
        return DB::transaction(function () use ($address, $investor, $admin) {
            $address = DepositAddress::query()->lockForUpdate()->findOrFail($address->id);

            if (! $address->is_active || $address->archived_at !== null) {
                throw new DomainException('Inactive or archived deposit address cannot be assigned.');
            }

            if ($address->investor_id !== null && $address->investor_id !== $investor->id) {
                throw new DomainException('Deposit address is already assigned to another investor.');
            }

            if ($address->investor_id === $investor->id && $address->is_personal) {
                return $address;
            }

            $old = ['investor_id' => $address->investor_id, 'is_personal' => $address->is_personal];
            $address->update([
                'investor_id' => $investor->id,
                'is_personal' => true,
                'assigned_at' => now(),
            ]);
            $this->audit->log('deposit_address.assigned', $address, $admin, $old, [
                'investor_id' => $investor->id, 'is_personal' => true,
            ]);

            return $address;
        });
    }

    public function unassignDepositAddress(DepositAddress $address, ?User $admin = null): DepositAddress
    {
        return DB::transaction(function () use ($address, $admin) {
            $address = DepositAddress::query()->lockForUpdate()->findOrFail($address->id);

            if ($address->investor_id === null && ! $address->is_personal) {
                return $address;
            }

            $old = ['investor_id' => $address->investor_id, 'is_personal' => $address->is_personal];
            $address->update(['investor_id' => null, 'is_personal' => false, 'assigned_at' => null]);
            $this->audit->log('deposit_address.unassigned', $address, $admin, $old, [
                'investor_id' => null, 'is_personal' => false,
            ]);

            return $address;
        });
    }

    public function approveInvestorWallet(InvestorWallet $wallet, User $admin): InvestorWallet
    {
        return $this->walletTransition($wallet, $admin, 'approved', ['pending'], 'investor_wallet.approved', [
            'approved_by' => $admin->id, 'approved_at' => now(), 'blocked_at' => null,
        ]);
    }

    public function blockInvestorWallet(InvestorWallet $wallet, User $admin): InvestorWallet
    {
        return $this->walletTransition($wallet, $admin, 'blocked', ['pending', 'approved'], 'investor_wallet.blocked', [
            'blocked_at' => now(),
        ]);
    }

    public function archiveInvestorWallet(InvestorWallet $wallet, User $admin): InvestorWallet
    {
        return $this->walletTransition($wallet, $admin, 'archived', ['pending', 'approved', 'blocked'], 'investor_wallet.archived', [
            'archived_at' => now(),
        ]);
    }

    public function archivePendingByInvestor(InvestorWallet $wallet, User $investorUser): InvestorWallet
    {
        return DB::transaction(function () use ($wallet, $investorUser) {
            $wallet = InvestorWallet::query()->lockForUpdate()->findOrFail($wallet->id);

            if ($investorUser->role !== 'investor' || $investorUser->investor?->id !== $wallet->investor_id) {
                throw new DomainException('Investor does not own this wallet.');
            }

            if ($wallet->status !== 'pending') {
                throw new DomainException('Only a pending wallet can be archived by investor.');
            }

            $wallet->update(['status' => 'archived', 'archived_at' => now()]);
            $this->audit->log('investor_wallet.archived_by_investor', $wallet, $investorUser, ['status' => 'pending'], ['status' => 'archived']);

            return $wallet;
        });
    }

    private function walletTransition(
        InvestorWallet $wallet,
        User $admin,
        string $target,
        array $allowedFrom,
        string $action,
        array $attributes,
    ): InvestorWallet {
        return DB::transaction(function () use ($wallet, $admin, $target, $allowedFrom, $action, $attributes) {
            $wallet = InvestorWallet::query()->lockForUpdate()->findOrFail($wallet->id);

            if ($wallet->status === $target) {
                return $wallet;
            }

            if (! in_array($wallet->status, $allowedFrom, true)) {
                throw new DomainException("Cannot transition investor wallet from {$wallet->status} to {$target}.");
            }

            $old = ['status' => $wallet->status];
            $wallet->update(array_merge($attributes, ['status' => $target]));
            $this->audit->log($action, $wallet, $admin, $old, ['status' => $target]);

            return $wallet;
        });
    }
}
