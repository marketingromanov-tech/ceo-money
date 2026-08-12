<?php

namespace App\Services;

use App\Models\DepositRequest;
use App\Models\InvestorWallet;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Notifications\AdminActionNotification;
use Illuminate\Support\Facades\Notification;

class AdminNotificationService
{
    public function depositCreated(DepositRequest $request): void
    {
        $this->send('deposit.created', 'Новая заявка на пополнение', $request->investor_id, [
            'amount' => (string) $request->requested_amount,
            'currency' => $request->currency,
            'target' => route('admin.deposits.index').'?deposit='.$request->id,
            'entity_type' => DepositRequest::class,
            'entity_id' => $request->id,
        ]);
    }

    public function depositPaymentSubmitted(DepositRequest $request): void
    {
        $this->send('deposit.payment_submitted', 'Инвестор сообщил об оплате', $request->investor_id, [
            'amount' => (string) $request->requested_amount,
            'currency' => $request->currency,
            'target' => route('admin.deposits.index').'?deposit='.$request->id,
            'entity_type' => DepositRequest::class,
            'entity_id' => $request->id,
        ]);
    }

    public function withdrawalCreated(WithdrawalRequest $request): void
    {
        $this->send('withdrawal.created', 'Новая заявка на вывод', $request->investor_id, [
            'amount' => (string) $request->requested_amount,
            'currency' => $request->currency,
            'target' => route('admin.withdrawals.index').'?withdrawal='.$request->id,
            'entity_type' => WithdrawalRequest::class,
            'entity_id' => $request->id,
        ]);
    }

    public function walletCreated(InvestorWallet $wallet): void
    {
        $this->send('wallet.created', 'Новый кошелёк ожидает проверки', $wallet->investor_id, [
            'currency' => $wallet->currency,
            'network' => $wallet->network,
            'target' => route('admin.wallets.index'),
            'entity_type' => InvestorWallet::class,
            'entity_id' => $wallet->id,
        ]);
    }

    private function send(string $event, string $title, int $investorId, array $data): void
    {
        $investor = \App\Models\Investor::with('user')->find($investorId);
        if ($investor === null) return;

        $payload = [
            'event' => $event,
            'title' => $title,
            'investor_name' => $investor->user->name,
            'investor_code' => $investor->code,
            ...$data,
        ];
        $admins = User::query()->where('role', 'admin')->where('is_active', true)->get();
        if ($admins->isNotEmpty()) Notification::send($admins, new AdminActionNotification($payload));
    }
}
