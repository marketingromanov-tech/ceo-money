<?php

namespace Tests\Feature;

use App\Models\DepositAddress;
use App\Models\AuditLog;
use App\Models\InvestmentAccount;
use App\Models\Investor;
use App\Models\User;
use App\Services\DepositRequestService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DepositRequestAddressTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_accepts_personal_address_and_keeps_snapshot_after_address_changes(): void
    {
        [$account, $investor] = $this->context();
        $address = $this->personalAddress($investor);
        $this->actingAs($investor->user);

        $request = app(DepositRequestService::class)->create($account, '100.00000000', $address, 'TRC20');
        $address->update(['address' => 'changed-address', 'provider' => 'changed-provider', 'archived_at' => now()]);

        $request->refresh();
        $this->assertSame($address->id, $request->deposit_address_id);
        $this->assertSame('personal-address', $request->deposit_address_snapshot);
        $this->assertSame('manual', $request->provider_snapshot);
        $this->assertSame('TRC20', $request->network);
        $log = AuditLog::where('action', 'deposit_request.created')->sole();
        $this->assertSame($investor->user_id, $log->user_id);
        $this->assertSame('personal-address', $log->new_values['deposit_address_snapshot']);
    }

    public function test_foreign_address_is_rejected(): void
    {
        [$account] = $this->context();
        [, $otherInvestor] = $this->context();

        $this->expectException(DomainException::class);
        app(DepositRequestService::class)->create($account, '100', $this->personalAddress($otherInvestor));
    }

    public function test_currency_mismatch_is_rejected(): void
    {
        [$account, $investor] = $this->context();

        $this->expectException(DomainException::class);
        app(DepositRequestService::class)->create(
            $account, '100', $this->personalAddress($investor, ['currency' => 'BTC']),
        );
    }

    public function test_network_mismatch_is_rejected(): void
    {
        [$account, $investor] = $this->context();

        $this->expectException(DomainException::class);
        app(DepositRequestService::class)->create(
            $account, '100', $this->personalAddress($investor), 'ERC20',
        );
    }

    public function test_archived_personal_address_cannot_be_used_for_a_new_request(): void
    {
        [$account, $investor] = $this->context();
        $address = $this->personalAddress($investor, ['archived_at' => now()]);

        $this->expectException(DomainException::class);
        app(DepositRequestService::class)->create($account, '100', $address);
    }

    private function context(): array
    {
        $investor = Investor::create(['user_id' => User::factory()->create()->id, 'status' => 'active']);
        $account = InvestmentAccount::create([
            'investor_id' => $investor->id, 'currency' => 'USDT', 'status' => 'active',
        ]);

        return [$account, $investor];
    }

    private function personalAddress(Investor $investor, array $attributes = []): DepositAddress
    {
        return DepositAddress::create(array_merge([
            'investor_id' => $investor->id, 'provider' => 'manual', 'currency' => 'USDT',
            'network' => 'TRC20', 'address' => 'personal-address',
            'is_personal' => true, 'is_active' => true, 'assigned_at' => now(),
        ], $attributes));
    }
}
