<?php

namespace App\Livewire\Investor\Concerns;

use App\Models\InvestmentAccount;
use App\Models\Investor;

trait InteractsWithInvestorData
{
    protected function investor(): Investor
    {
        return Investor::query()->where('user_id', auth()->id())->firstOrFail();
    }

    protected function account(): InvestmentAccount
    {
        return InvestmentAccount::query()
            ->where('investor_id', $this->investor()->id)
            ->where('status', 'active')
            ->firstOrFail();
    }
}
