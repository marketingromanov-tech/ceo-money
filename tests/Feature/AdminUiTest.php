<?php

namespace Tests\Feature;

use App\Livewire\Admin\Investors\Create;
use App\Livewire\Admin\Investors\Index;
use App\Livewire\Admin\Investors\Show;
use App\Livewire\Admin\Withdrawals\Index as WithdrawalsIndex;
use App\Models\InvestmentAccount;
use App\Models\AuditLog;
use App\Models\DepositRequest;
use App\Models\Investor;
use App\Models\User;
use App\Models\WithdrawalRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_investor_cannot_open_admin_area(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'investor']))
            ->get('/admin')->assertForbidden();
    }

    public function test_admin_can_open_dashboard_and_admin_lists(): void
    {
        $this->actingAs($this->admin());

        $this->get('/admin')->assertOk()->assertSee('Финансовый обзор');
        $this->get('/admin/investors')->assertOk()->assertSee('Инвесторы');
        $this->get('/admin/deposits')->assertOk()->assertSee('Пополнения');
        $this->get('/admin/withdrawals')->assertOk()->assertSee('Выводы');
    }

    public function test_creating_investor_creates_user_profile_and_account_without_lot(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(Create::class)
            ->set('name', 'Новый Инвестор')
            ->set('email', 'new-investor@example.com')
            ->set('password', 'password123')
            ->set('phone', '+7 900 000-00-00')
            ->set('code', 'INV-NEW')
            ->set('contractNumber', 'C-100')
            ->set('contractDate', '2026-08-10')
            ->set('status', 'active')
            ->call('save')
            ->assertHasNoErrors();

        $user = User::where('email', 'new-investor@example.com')->firstOrFail();
        $investor = Investor::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('investor', $user->role);
        $this->assertDatabaseHas('investment_accounts', ['investor_id' => $investor->id, 'currency' => 'USDT']);
        $this->assertDatabaseCount('investment_lots', 0);
        $log = AuditLog::where('action', 'investor.created')->sole();
        $this->assertSame($investor->id, $log->entity_id);
        $this->assertSame('INV-NEW', $log->new_values['code']);
        $this->assertSame('USDT', $log->new_values['currency']);
        $this->assertArrayNotHasKey('password', $log->new_values);
        $this->assertStringNotContainsString('password123', json_encode($log->new_values));
    }

    public function test_investor_search_works(): void
    {
        $this->actingAs($this->admin());
        $visible = $this->investor('Alpha Capital', 'alpha@example.com', 'INV-ALPHA');
        $this->investor('Beta Group', 'beta@example.com', 'INV-BETA');

        Livewire::test(Index::class)
            ->set('search', 'Alpha')
            ->assertSee($visible->user->name)
            ->assertDontSee('Beta Group');
    }

    public function test_investor_show_route_and_month_switching_work(): void
    {
        $this->actingAs($this->admin());
        $investor = $this->investor('Investor Show', 'show@example.com', 'INV-SHOW');

        $this->get(route('admin.investors.show', $investor))->assertOk()->assertSee('Investor Show');

        Livewire::test(Show::class, ['investor' => $investor])
            ->set('activeTab', 'accruals')
            ->assertSet('selectedMonth', now()->format('Y-m'))
            ->call('previousMonth')
            ->assertSet('selectedMonth', now()->subMonth()->format('Y-m'))
            ->call('nextMonth')
            ->assertSet('selectedMonth', now()->format('Y-m'));
    }

    public function test_admin_withdrawals_use_localized_presentation_and_keep_db_filter_values(): void
    {
        $this->actingAs($this->admin());
        $investor = $this->investor('Дмитрий Орлов', 'dmitry-withdrawals@example.com', 'INV-WITHDRAWALS');
        $account = $investor->investmentAccounts()->sole();
        foreach ([
            ['dividend', 'review', '15.00000000', '0.15000000', '14.85000000', 'TDmitryDemoWallet001'],
            ['capital', 'paid', '100.00000000', '1.00000000', '99.00000000', 'TCapitalDemoWallet002'],
        ] as [$type, $status, $requested, $fee, $net, $address]) {
            WithdrawalRequest::create([
                'investor_id'=>$investor->id, 'investment_account_id'=>$account->id,
                'type'=>$type, 'requested_amount'=>$requested, 'reserved_amount'=>'0.00000000',
                'fee_amount'=>$fee, 'net_amount'=>$net, 'currency'=>'USDT',
                'network_snapshot'=>'TRC20', 'wallet_address_snapshot'=>$address,
                'status'=>$status, 'requested_at'=>now(),
            ]);
        }

        Livewire::test(WithdrawalsIndex::class)
            ->assertSee('Вывод дивидендов')->assertSee('Вывод капитала')
            ->assertSee('На проверке')->assertSee('Выплачена')
            ->assertSee('15.00 USDT')->assertSee('0.15 USDT')->assertSee('14.85 USDT')
            ->assertSee('TDmitr…t001')->assertSee('TDmitryDemoWallet001')
            ->assertSee('<option value="review">На проверке</option>', false)
            ->assertSee('<option value="paid">Выплачена</option>', false)
            ->assertSee('<option value="dividend">Дивиденды</option>', false)
            ->assertSee('<option value="capital">Капитал</option>', false)
            ->set('status', 'review')->assertViewHas('withdrawals', fn ($items) => $items->total() === 1 && $items->first()->status === 'review')
            ->set('status', '')->set('type', 'capital')->assertViewHas('withdrawals', fn ($items) => $items->total() === 1 && $items->first()->type === 'capital');
    }

    public function test_investor_still_cannot_open_admin_withdrawals(): void
    {
        $this->actingAs(User::factory()->create(['role'=>'investor']))
            ->get('/admin/withdrawals')->assertForbidden();
    }

    public function test_admin_deposits_share_table_style_and_localized_status_filter(): void
    {
        $this->actingAs($this->admin());
        $investor = $this->investor('Мария Волкова', 'maria-deposits@example.com', 'INV-DEPOSITS');
        $account = $investor->investmentAccounts()->sole();
        DepositRequest::create([
            'investor_id'=>$investor->id, 'investment_account_id'=>$account->id,
            'requested_amount'=>'5000.00000000', 'currency'=>'USDT', 'network'=>'TRC20',
            'deposit_address_snapshot'=>'TMariaDepositDemo001', 'txid'=>'abcdef1234567890longtxid999999',
            'status'=>'submitted', 'requested_at'=>now(), 'submitted_at'=>now(),
        ]);

        Livewire::test(\App\Livewire\Admin\Deposits\Index::class)
            ->assertSee('Проверка')->assertSee('5 000.00 USDT')
            ->assertSee('Мария Волкова')->assertSee('TRC20')
            ->assertSee('TMaria…o001')->assertSee('TMariaDepositDemo001')
            ->assertSee('abcdef12…999999')->assertSee('abcdef1234567890longtxid999999')
            ->assertSee('<option value="submitted">Проверка</option>', false)
            ->assertSee('bg-[#3c354a]', false)->assertSee('bg-[#332c42]', false)
            ->assertSee('bg-[#3a3348]/45', false)->assertSee('hover:bg-white/[.04]', false)
            ->set('status', 'submitted')
            ->assertViewHas('deposits', fn ($items) => $items->total() === 1 && $items->first()->status === 'submitted');
    }

    public function test_investor_still_cannot_open_admin_deposits(): void
    {
        $this->actingAs(User::factory()->create(['role'=>'investor']))
            ->get('/admin/deposits')->assertForbidden();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_active' => true]);
    }

    private function investor(string $name, string $email, string $code): Investor
    {
        $user = User::factory()->create(['name' => $name, 'email' => $email, 'role' => 'investor']);
        $investor = Investor::create(['user_id' => $user->id, 'code' => $code, 'status' => 'active']);
        InvestmentAccount::create(['investor_id' => $investor->id, 'currency' => 'USDT', 'status' => 'active']);

        return $investor;
    }
}
