<?php

namespace App\Livewire\Admin\Investors;

use App\Models\InvestmentAccount;
use App\Models\Investor;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.admin')]
class Create extends Component
{
    public string $name = '';
    public string $email = '';
    public string $password = '';
    public string $phone = '';
    public string $code = '';
    public string $contractNumber = '';
    public string $contractDate = '';
    public string $status = 'active';

    public function save(AuditLogService $audit)
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8'],
            'phone' => ['nullable', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:255', Rule::unique('investors', 'code')],
            'contractNumber' => ['nullable', 'string', 'max:255'],
            'contractDate' => ['nullable', 'date'],
            'status' => ['required', Rule::in(['active', 'inactive', 'suspended'])],
        ]);

        $investor = DB::transaction(function () use ($data, $audit) {
            $user = User::create([
                'name' => $data['name'], 'email' => $data['email'],
                'password' => Hash::make($data['password']), 'role' => 'investor', 'is_active' => true,
            ]);
            $investor = Investor::create([
                'user_id' => $user->id, 'phone' => $data['phone'] ?: null,
                'code' => $data['code'] ?: null, 'contract_number' => $data['contractNumber'] ?: null,
                'contract_date' => $data['contractDate'] ?: null, 'status' => $data['status'],
            ]);
            $account = InvestmentAccount::create(['investor_id' => $investor->id, 'currency' => 'USDT', 'status' => 'active']);
            $audit->log('investor.created', $investor, auth()->user(), null, [
                'user_id' => $user->id, 'investor_id' => $investor->id,
                'investment_account_id' => $account->id, 'name' => $user->name,
                'email' => $user->email, 'code' => $investor->code,
                'currency' => $account->currency, 'account_status' => $account->status,
                'contract_number' => $investor->contract_number,
                'contract_date' => $investor->contract_date?->toDateString(),
            ]);

            return $investor;
        });

        return $this->redirectRoute('admin.investors.show', $investor, navigate: true);
    }

    public function render() { return view('livewire.admin.investors.create')->title('Новый инвестор — CEO Money'); }
}
