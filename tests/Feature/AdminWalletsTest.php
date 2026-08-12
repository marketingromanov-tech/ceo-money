<?php

namespace Tests\Feature;

use App\Livewire\Admin\Wallets\Index;
use App\Models\DepositAddress;
use App\Models\DepositRequest;
use App\Models\InvestmentAccount;
use App\Models\Investor;
use App\Models\InvestorWallet;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\DepositAddressService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminWalletsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_access_investor_forbidden_tabs_and_sidebar_route(): void
    {
        [$admin, $investorUser] = $this->context();
        $this->actingAs($admin)->get(route('admin.wallets.index'))->assertOk()->assertSee('Кошельки инвесторов')->assertSee('Адреса пополнения');
        $this->actingAs($investorUser)->get(route('admin.wallets.index'))->assertForbidden();
        $this->assertSame('/admin/wallets', route('admin.wallets.index', absolute: false));
    }

    public function test_wallet_kpis_list_status_presentation_expand_copy_and_mobile_markup(): void
    {
        [$admin, , $investor] = $this->context();
        foreach (['pending','approved','blocked','archived'] as $status) $this->wallet($investor, $status, 'T'.$status.'WalletAddress001');

        Livewire::actingAs($admin)->test(Index::class)
            ->assertViewHas('walletKpis', fn($k)=>$k===['total'=>4,'pending'=>1,'approved'=>1,'archived'=>1])
            ->assertSee('На проверке')->assertSee('Одобрен')->assertSee('Заблокирован')->assertSee('Архивирован')
            ->assertDontSee('>pending<', false)->assertSee('Копировать')->assertDontSee('>Copy<', false)
            ->assertSee('x-data="{ expanded:false }"', false)->assertSee('x-bind:aria-expanded="expanded"', false)
            ->assertSee('aria-controls="wallet-details-', false)->assertSee('minmax(120px,auto)', false)
            ->assertSee('aria-hidden="true"', false)->assertSee('lg:hidden', false)->assertSee('Подробнее')
            ->assertSee('Одобрить')->assertSee('Заблокировать')->assertSee('Архивировать');
    }

    public function test_wallet_filters_and_pending_first_pagination(): void
    {
        [$admin, , $investor] = $this->context();
        $this->wallet($investor, 'approved', 'TErcApprovedAddress', ['network'=>'ERC20']);
        for ($i=1;$i<=21;$i++) $this->wallet($investor, 'pending', 'TPendingAddress'.str_pad((string)$i,3,'0',STR_PAD_LEFT));

        Livewire::actingAs($admin)->test(Index::class)
            ->set('walletStatus','approved')->assertSee('TErcAp…ress')->assertDontSee('TPendi…s001')
            ->set('walletStatus','')->set('walletNetwork','ERC20')->assertSee('TErcAp…ress')
            ->set('walletNetwork','')->set('walletSearch',$investor->user->email)->assertViewHas('wallets',fn($p)=>$p->total()===22)
            ->assertViewHas('wallets',fn($p)=>$p->perPage()===20);
    }

    public function test_approve_is_idempotent_archiving_and_blocking_use_service_and_audit(): void
    {
        [$admin, , $investor] = $this->context(); $pending=$this->wallet($investor,'pending','TApproveAddress');
        $component=Livewire::actingAs($admin)->test(Index::class)->call('confirm','approve-wallet',$pending->id)
            ->assertSee('max-w-md overflow-hidden rounded-[18px]', false)
            ->call('executeConfirmed')->assertHasNoErrors();
        $this->assertSame('approved',$pending->fresh()->status); $this->assertSame($admin->id,$pending->fresh()->approved_by);
        $component->call('confirm','approve-wallet',$pending->id)->call('executeConfirmed')->assertHasNoErrors();
        $this->assertDatabaseCount('audit_logs',1);
        $component->call('confirm','block-wallet',$pending->id)->call('executeConfirmed'); $this->assertSame('blocked',$pending->fresh()->status);
        $component->call('confirm','archive-wallet',$pending->id)->call('executeConfirmed'); $this->assertSame('archived',$pending->fresh()->status);
        $this->assertDatabaseHas('audit_logs',['action'=>'investor_wallet.approved']); $this->assertDatabaseHas('audit_logs',['action'=>'investor_wallet.blocked']); $this->assertDatabaseHas('audit_logs',['action'=>'investor_wallet.archived']);
    }

    public function test_deposit_address_tab_kpis_list_create_provider_assign_and_archive(): void
    {
        [$admin, , $investor] = $this->context();
        $component=Livewire::actingAs($admin)->test(Index::class)->call('selectTab','addresses')->call('openAddressModal')
            ->assertSee('max-w-xl overflow-hidden rounded-[18px]', false)->assertSee('Provider')
            ->set('newInvestorId',$investor->id)->set('newCurrency','USDT')->set('newNetwork','TRC20')->set('newAddress','TProviderAgnosticAddress001')->set('newProvider','custom-provider')->call('createAddress')->assertHasNoErrors();
        $address=DepositAddress::sole(); $this->assertSame($investor->id,$address->investor_id); $this->assertSame('custom-provider',$address->provider); $this->assertTrue($address->is_personal);
        $component->assertViewHas('addressKpis',fn($k)=>$k===['total'=>1,'active'=>1,'assigned'=>1,'archived'=>0])->assertSee('Активен')->assertSee('custom-provider')->assertSee('Копировать')->assertDontSee('>Copy<', false)->assertSee('aria-controls="address-details-', false);
        $this->assertDatabaseHas('audit_logs',['action'=>'deposit_address.created']); $this->assertDatabaseHas('audit_logs',['action'=>'deposit_address.assigned']);
        $component->call('confirm','archive-address',$address->id)->call('executeConfirmed')->assertHasNoErrors();
        $this->assertFalse($address->fresh()->is_active); $this->assertNotNull($address->fresh()->archived_at); $this->assertDatabaseHas('audit_logs',['action'=>'deposit_address.archived']);
    }

    public function test_existing_free_address_can_be_assigned_but_not_reassigned_to_another_investor(): void
    {
        [$admin, , $investor] = $this->context(); [, , $other] = $this->context('other-wallet-admin@example.com');
        $address=DepositAddress::create(['currency'=>'USDT','network'=>'TRC20','address'=>'TFreeAddress','is_active'=>true,'is_personal'=>false]);
        Livewire::actingAs($admin)->test(Index::class)->call('selectTab','addresses')->call('openAssign',$address->id)->set('assignInvestorId',$investor->id)->call('assignAddress')->assertHasNoErrors();
        $this->assertSame($investor->id,$address->fresh()->investor_id);
        $this->expectException(\DomainException::class); app(WalletService::class)->assignDepositAddress($address,$other,$admin);
    }

    public function test_archiving_wallet_and_deposit_address_does_not_change_request_snapshots(): void
    {
        [$admin, , $investor, $account] = $this->context();
        $wallet=app(WalletService::class)->approveInvestorWallet($this->wallet($investor,'pending','TImmutableWalletAddress'),$admin);
        $withdrawal=WithdrawalRequest::create(['investor_id'=>$investor->id,'investment_account_id'=>$account->id,'investor_wallet_id'=>$wallet->id,'type'=>'dividend','requested_amount'=>'10.00000000','reserved_amount'=>'10.00000000','fee_amount'=>'0.00000000','fee_payer'=>'investor','net_amount'=>'10.00000000','currency'=>'USDT','wallet_address_snapshot'=>$wallet->address,'network_snapshot'=>$wallet->network,'status'=>'new','requested_at'=>now()]);
        app(WalletService::class)->archiveInvestorWallet($wallet,$admin);
        $this->assertSame('TImmutableWalletAddress',$withdrawal->fresh()->wallet_address_snapshot); $this->assertSame('TRC20',$withdrawal->fresh()->network_snapshot);
        $address=app(DepositAddressService::class)->create(['currency'=>'USDT','network'=>'TRC20','address'=>'TImmutableDepositAddress','provider'=>'manual'], $investor, $admin);
        $deposit=DepositRequest::create(['investor_id'=>$investor->id,'investment_account_id'=>$account->id,'deposit_address_id'=>$address->id,'requested_amount'=>'20.00000000','currency'=>'USDT','network'=>'TRC20','deposit_address_snapshot'=>$address->address,'provider_snapshot'=>$address->provider,'status'=>'submitted','requested_at'=>now()]);
        app(DepositAddressService::class)->archive($address,$admin);
        $this->assertSame('TImmutableDepositAddress',$deposit->fresh()->deposit_address_snapshot); $this->assertSame('manual',$deposit->fresh()->provider_snapshot);
    }

    private function context(string $email='wallet-investor@example.com'): array
    {
        $admin=User::factory()->create(['role'=>'admin','is_active'=>true]); $user=User::factory()->create(['role'=>'investor','is_active'=>true,'email'=>$email]);
        $investor=Investor::create(['user_id'=>$user->id,'code'=>'INV-'.User::count(),'status'=>'active']); $account=InvestmentAccount::create(['investor_id'=>$investor->id,'currency'=>'USDT','status'=>'active']);
        return [$admin,$user,$investor,$account];
    }

    private function wallet(Investor $investor,string $status,string $address,array $attributes=[]): InvestorWallet
    {
        return InvestorWallet::create(array_merge(['investor_id'=>$investor->id,'currency'=>'USDT','network'=>'TRC20','address'=>$address,'label'=>'Основной кошелёк','status'=>$status],$attributes));
    }
}
