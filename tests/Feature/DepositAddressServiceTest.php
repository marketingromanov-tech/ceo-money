<?php

namespace Tests\Feature;

use App\Models\DepositAddress;
use App\Models\Investor;
use App\Models\InvestorWallet;
use App\Models\User;
use App\Services\DepositAddressService;
use App\Services\WalletService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DepositAddressServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_free_address_can_be_assigned_and_unassigned_without_deletion(): void
    {
        [$investor, $admin] = $this->context();
        $address = $this->address();
        $wallets = app(WalletService::class);

        $assigned = $wallets->assignDepositAddress($address, $investor, $admin);
        $this->assertSame($investor->id, $assigned->investor_id);
        $this->assertTrue($assigned->is_personal);
        $this->assertNotNull($assigned->assigned_at);

        $unassigned = $wallets->unassignDepositAddress($assigned, $admin);
        $this->assertNull($unassigned->investor_id);
        $this->assertFalse($unassigned->is_personal);
        $this->assertNull($unassigned->assigned_at);
        $this->assertDatabaseCount('deposit_addresses', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'deposit_address.assigned']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'deposit_address.unassigned']);
    }

    public function test_address_cannot_be_assigned_to_a_second_investor(): void
    {
        [$investor, $admin] = $this->context();
        [$otherInvestor] = $this->context();
        $address = app(WalletService::class)->assignDepositAddress($this->address(), $investor, $admin);

        $this->expectException(DomainException::class);
        app(WalletService::class)->assignDepositAddress($address, $otherInvestor, $admin);
    }

    public function test_inactive_and_archived_addresses_cannot_be_assigned(): void
    {
        [$investor, $admin] = $this->context();

        foreach ([['is_active' => false], ['archived_at' => now()]] as $attributes) {
            try {
                app(WalletService::class)->assignDepositAddress($this->address($attributes), $investor, $admin);
                $this->fail('Expected assignment to fail.');
            } catch (DomainException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_available_and_personal_address_filters_are_correct(): void
    {
        [$investor, $admin] = $this->context();
        $free = $this->address(['address' => 'free']);
        $personal = app(WalletService::class)->assignDepositAddress(
            $this->address(['address' => 'personal']), $investor, $admin,
        );
        $this->address(['address' => 'inactive', 'is_active' => false]);
        $this->address(['address' => 'archived', 'archived_at' => now()]);
        $this->address(['address' => 'other-network', 'network' => 'ERC20']);
        $service = app(DepositAddressService::class);

        $this->assertEquals([$free->id], $service->availableAddresses('USDT', 'TRC20')->modelKeys());
        $this->assertEquals([$personal->id], $service->personalAddresses($investor)->modelKeys());
    }

    public function test_wallet_status_changes_are_audited(): void
    {
        [$investor, $admin] = $this->context();
        $wallet = InvestorWallet::create([
            'investor_id' => $investor->id, 'currency' => 'USDT',
            'network' => 'TRC20', 'address' => 'wallet-a',
        ]);
        $service = app(WalletService::class);

        $service->approveInvestorWallet($wallet, $admin);
        $service->blockInvestorWallet($wallet, $admin);
        $service->archiveInvestorWallet($wallet, $admin);

        $this->assertSame('archived', $wallet->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'investor_wallet.approved']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'investor_wallet.blocked']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'investor_wallet.archived']);
    }

    private function context(): array
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $investor = Investor::create(['user_id' => User::factory()->create()->id, 'status' => 'active']);

        return [$investor, $admin];
    }

    private function address(array $attributes = []): DepositAddress
    {
        return DepositAddress::create(array_merge([
            'provider' => 'manual', 'currency' => 'USDT', 'network' => 'TRC20',
            'address' => 'address-'.uniqid(), 'is_active' => true,
        ], $attributes));
    }
}
