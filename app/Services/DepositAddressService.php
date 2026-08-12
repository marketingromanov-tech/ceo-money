<?php

namespace App\Services;

use App\Models\DepositAddress;
use App\Models\Investor;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class DepositAddressService
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly AuditLogService $audit,
    ) {
    }

    public function create(array $attributes, ?Investor $investor, User $admin): DepositAddress
    {
        $this->assertAdmin($admin);

        return DB::transaction(function () use ($attributes, $investor, $admin) {
            $address = DepositAddress::create([
                ...$attributes,
                'investor_id' => null,
                'is_personal' => false,
                'is_active' => true,
                'created_by' => $admin->id,
            ]);
            $this->audit->log('deposit_address.created', $address, $admin, null, $address->getAttributes());

            return $investor ? $this->wallets->assignDepositAddress($address, $investor, $admin) : $address;
        });
    }

    public function archive(DepositAddress $address, User $admin): DepositAddress
    {
        $this->assertAdmin($admin);

        return DB::transaction(function () use ($address, $admin) {
            $address = DepositAddress::query()->lockForUpdate()->findOrFail($address->id);
            if ($address->archived_at !== null) {
                return $address;
            }

            $old = ['is_active' => $address->is_active, 'archived_at' => null];
            $address->update(['is_active' => false, 'archived_at' => now()]);
            $this->audit->log('deposit_address.archived', $address, $admin, $old, [
                'is_active' => false, 'archived_at' => $address->archived_at,
            ]);

            return $address;
        });
    }

    /** @return Collection<int, DepositAddress> */
    public function availableAddresses(string $currency, string $network): Collection
    {
        return DepositAddress::query()
            ->where('currency', $currency)
            ->where('network', $network)
            ->where('is_active', true)
            ->whereNull('archived_at')
            ->whereNull('investor_id')
            ->get();
    }

    /** @return Collection<int, DepositAddress> */
    public function personalAddresses(Investor $investor): Collection
    {
        return $investor->depositAddresses()
            ->where('is_personal', true)
            ->where('is_active', true)
            ->whereNull('archived_at')
            ->get();
    }

    private function assertAdmin(User $admin): void
    {
        if ($admin->role !== 'admin' || ! $admin->is_active) {
            throw new DomainException('Only an active administrator may manage deposit addresses.');
        }
    }
}
