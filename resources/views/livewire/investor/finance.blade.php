@php use App\Support\MoneyFormatter; use App\Support\InvestorPresentation; @endphp
<div class="mx-auto max-w-[1500px] space-y-5">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div><h2 class="text-2xl font-semibold">Финансы</h2><p class="mt-1 text-sm text-[#9587a7]">Ваш капитал, инвестиции и история операций</p></div>
        <a href="{{ route('investor.programs') }}" wire:navigate class="inline-flex w-full cursor-pointer items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-[#ac6aec] to-[#6875e9] px-4 py-3 text-sm font-semibold text-white transition hover:brightness-110 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cyan-300/60 sm:w-auto">
            <svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 5v14M5 12h14"/></svg>
            Новая инвестиция
        </a>
    </div>

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        @foreach([
            ['Общий капитал', MoneyFormatter::format($financeSummary['capital']).' USDT'],
            ['Активных инвестиций', $financeSummary['activeLots']],
            ['Доступно к выводу', MoneyFormatter::format($financeSummary['availableCapital']).' USDT'],
            ['Заблокировано', MoneyFormatter::format($financeSummary['lockedCapital']).' USDT'],
        ] as [$label,$value])
            <div class="rounded-[18px] border border-white/[.07] bg-[#3c354a] p-4"><p class="text-xs text-[#9789a9]">{{ $label }}</p><p class="mt-2 text-xl font-semibold {{ in_array($label,['Доступно к выводу','Заблокировано']) ? 'text-cyan-100' : '' }}">{{ $value }}</p></div>
        @endforeach
    </div>

    <section class="rounded-[20px] border border-white/[.07] bg-[#3c354a] p-4 sm:p-5">
        <div class="flex items-center gap-3"><span class="flex size-10 items-center justify-center rounded-xl bg-cyan-200/[.06] text-cyan-200"><svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M12 3v12m-5-5 5 5 5-5M5 21h14"/></svg></span><div><h3 class="font-semibold">Реквизиты для пополнения</h3><p class="text-xs text-[#9183a2]">Постоянные реквизиты, назначенные администратором</p></div></div>
        <div class="mt-3">
            @if($activePaymentDetail)
                @php $address=$activePaymentDetail->address;$shortAddress=mb_strlen($address)>18?mb_substr($address,0,9).'…'.mb_substr($address,-6):$address; @endphp
                <article class="rounded-2xl bg-[#2d2639] p-4"><div class="flex flex-wrap items-center justify-between gap-2"><span class="rounded-lg bg-purple-300/[.08] px-2 py-1 text-xs text-purple-100">{{ $activePaymentDetail->currency }} · {{ $activePaymentDetail->network }}</span></div><p class="mt-3 font-mono text-sm text-cyan-100">{{ $shortAddress }}</p><div class="mt-2 flex items-start gap-2"><code class="min-w-0 flex-1 break-all text-xs leading-5 text-[#a99db7]">{{ $address }}</code><button type="button" onclick="navigator.clipboard.writeText(@js($address)); this.textContent='Скопировано'" class="shrink-0 cursor-pointer rounded-xl border border-cyan-300/20 px-3 py-2 text-xs text-cyan-200">Копировать</button></div>@if(filled($activePaymentDetail->memo))<p class="mt-3 text-xs text-[#a99db7]">Memo/Tag: <span class="font-mono text-white">{{ $activePaymentDetail->memo }}</span></p>@endif</article>
            @else
                <div class="rounded-2xl border border-white/[.05] bg-[#2d2639] px-4 py-3"><p class="text-sm font-medium">Администратор ещё не назначил реквизиты для пополнения</p></div>
            @endif
        </div>
    </section>

    @if($depositRequests->isNotEmpty())
        <section class="rounded-[20px] border border-white/[.07] bg-[#3c354a] p-4 sm:p-5">
            <div><h3 class="font-semibold">Заявки на пополнение</h3><p class="mt-1 text-xs text-[#9183a2]">Текущий статус обработки ваших пополнений</p></div>
            <div class="mt-4 space-y-2">
                @foreach($depositRequests as $request)
                    <article class="flex flex-col gap-3 rounded-2xl border border-white/[.05] bg-[#2d2639] p-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>@if(data_get($request->investment_program_snapshot, 'name') || $request->investmentProgram)<p class="text-sm font-semibold text-purple-100">{{ data_get($request->investment_program_snapshot, 'name', $request->investmentProgram?->name) }}</p>@endif<p class="text-sm font-medium">{{ MoneyFormatter::format($request->requested_amount) }} {{ $request->currency }}</p><p class="mt-1 text-xs text-[#9183a2]">{{ $request->requested_at->format('d.m.Y H:i') }} · {{ $request->network ?: 'Сеть не указана' }}</p></div>
                        <div class="sm:text-right">@if($request->status==='pending' && ($request->payment_details_snapshot || $activePaymentDetail))<p class="mb-2 text-xs text-purple-100">Ожидает оплаты</p><button type="button" wire:click="openPayment({{ $request->id }})" class="cursor-pointer rounded-xl bg-gradient-to-r from-[#ac6aec] to-[#6875e9] px-4 py-2.5 text-xs font-semibold">Оплатить</button>@elseif($request->status==='pending')<span class="inline-flex rounded-full bg-white/[.05] px-2.5 py-1 text-xs text-[#a99db7]">Реквизиты ожидаются</span>@elseif($request->status==='payment_submitted')<p class="text-xs font-medium text-cyan-100">Платёж отправлен</p><p class="mt-1 text-[11px] text-[#9183a2]">Ожидает проверки администратора</p>@else<span class="inline-flex rounded-full bg-purple-300/10 px-2.5 py-1 text-xs text-purple-100">{{ InvestorPresentation::investorDepositStatus($request->status) }}</span>@endif @if($request->status === 'rejected' && $request->rejected_reason)<p class="mt-2 max-w-md text-xs text-rose-200">Причина: {{ $request->rejected_reason }}</p>@endif</div>
                    </article>
                @endforeach
            </div>
        </section>
    @endif

    @if($showPaymentModal && $selectedPaymentRequest)
        @php $pay=$selectedPaymentRequest->payment_details_snapshot; @endphp
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-[#171020]/80 p-4 backdrop-blur-sm" wire:keydown.escape="closePayment"><section role="dialog" aria-modal="true" aria-label="Оплата инвестиции" class="w-full max-w-lg overflow-hidden rounded-[20px] border border-white/10 bg-[#3c354a] shadow-2xl"><header class="flex items-center justify-between border-b border-white/[.07] p-5"><div><h3 class="font-semibold">Оплата инвестиции</h3><p class="mt-1 text-xs text-[#9183a2]">{{ data_get($selectedPaymentRequest->investment_program_snapshot,'name','Инвестиционная программа') }}</p></div><button type="button" wire:click="closePayment" class="cursor-pointer rounded-xl p-2 text-[#9b8ba9] hover:bg-white/[.05] hover:text-white">✕</button></header><div class="space-y-4 p-5"><div class="rounded-2xl bg-[#2d2639] p-4"><p class="text-xs text-[#9183a2]">Точная сумма к отправке</p><p class="mt-1 text-2xl font-semibold">{{ MoneyFormatter::format($selectedPaymentRequest->requested_amount) }} <span class="text-sm text-cyan-200">{{ $selectedPaymentRequest->currency }}</span></p></div><dl class="space-y-3 text-sm"><div><dt class="text-xs text-[#9183a2]">Валюта</dt><dd class="mt-1 font-medium">{{ $pay['currency'] }}</dd></div><div><dt class="text-xs text-[#9183a2]">Сеть</dt><dd class="mt-1 font-medium">{{ $pay['network'] }}</dd></div><div><dt class="text-xs text-[#9183a2]">Адрес</dt><dd class="mt-1 flex items-start gap-2"><code class="min-w-0 flex-1 break-all rounded-xl bg-[#2d2639] p-3 text-xs text-cyan-100">{{ $pay['address'] }}</code><button type="button" onclick="navigator.clipboard.writeText(@js($pay['address']));this.textContent='Скопировано'" class="cursor-pointer rounded-xl border border-cyan-300/20 px-3 py-3 text-xs text-cyan-200">Копировать</button></dd></div>@if(filled($pay['memo']??null))<div><dt class="text-xs text-[#9183a2]">Memo/Tag</dt><dd class="mt-1 font-mono">{{ $pay['memo'] }}</dd></div>@endif</dl>@error('payment')<p class="text-sm text-rose-200">{{ $message }}</p>@enderror</div><footer class="border-t border-white/[.07] p-5"><button type="button" wire:click="markPaid" wire:loading.attr="disabled" class="w-full cursor-pointer rounded-xl bg-gradient-to-r from-[#ac6aec] to-[#6875e9] px-4 py-3 font-semibold disabled:cursor-not-allowed disabled:opacity-60">Я оплатил</button></footer></section></div>
    @endif

    <section class="rounded-[20px] border border-white/[.07] bg-[#3c354a] p-4 sm:p-6">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div><h3 class="text-lg font-semibold">Мои инвестиции</h3><p class="mt-1 text-xs text-[#9183a2]">Отдельные вложения, их доходность и сроки доступности</p></div>
            <div class="flex max-w-full flex-col gap-2 sm:flex-row sm:items-center">
                <div class="flex max-w-full gap-1 overflow-x-auto rounded-xl bg-[#2c2538] p-1" aria-label="Фильтр инвестиций">
                    @foreach(['active' => 'Активные', 'closed' => 'Завершённые', 'all' => 'Все'] as $filter => $label)
                        <button type="button" wire:click="setInvestmentFilter('{{ $filter }}')" class="shrink-0 cursor-pointer rounded-lg px-3 py-2 text-xs font-medium transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cyan-300/60 {{ $investmentFilter === $filter ? 'bg-purple-400/15 text-purple-100' : 'text-[#9385a3] hover:bg-white/[.04] hover:text-white' }}">{{ $label }} {{ $investmentCounts[$filter] }}</button>
                    @endforeach
                </div>
                <a href="{{ route('investor.programs') }}" wire:navigate class="inline-flex shrink-0 cursor-pointer items-center justify-center gap-1.5 rounded-xl border border-cyan-300/20 bg-cyan-300/[.06] px-3 py-2 text-xs font-semibold text-cyan-100 transition hover:border-cyan-300/35 hover:bg-cyan-300/[.1] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cyan-300/60">
                    <svg viewBox="0 0 24 24" class="size-3.5" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M12 5v14M5 12h14"/></svg>
                    Пополнить
                </a>
            </div>
        </div>

        @if($lots->isEmpty())
            <div class="mt-5 rounded-xl border border-white/[.05] bg-white/[.025] px-4 py-6 text-center">
                <p class="text-sm text-[#9183a2]">У вас пока нет инвестиций.</p>
                <a href="{{ route('investor.programs') }}" wire:navigate class="mt-4 inline-flex cursor-pointer items-center justify-center rounded-xl border border-purple-300/20 px-4 py-2.5 text-sm font-medium text-purple-100 transition hover:bg-purple-300/[.08] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cyan-300/60">Выбрать инвестиционную программу</a>
            </div>
        @elseif($visibleLots->isEmpty())
            <p class="mt-5 rounded-xl border border-white/[.05] bg-white/[.025] px-4 py-6 text-center text-sm text-[#9183a2]">{{ $investmentFilter === 'closed' ? 'Нет завершённых инвестиций.' : 'Нет активных инвестиций.' }}</p>
        @else
            <div class="mt-5 hidden grid-cols-[minmax(180px,1.35fr)_minmax(145px,1fr)_80px_120px_minmax(175px,1.25fr)_28px] gap-4 px-4 text-[10px] uppercase tracking-[.08em] text-[#81748f] lg:grid">
                <span>Инвестиция</span><span>Текущий капитал</span><span>Ставка</span><span>Начисления с</span><span>Доступность</span><span></span>
            </div>
            <div class="mt-2 space-y-2">
                @foreach($visibleLots as $lot)
                    @php
                        $availability = $lot->status === 'closed' ? 'Завершена' : ($lot->is_withdrawable ? 'Доступна к выводу' : 'Заблокирована до '.($lot->unlock_date?->format('d.m.Y') ?? 'уточнения условий'));
                        $detailId = 'investment-details-'.$lot->display_number;
                    @endphp
                    <article x-data="{ expanded: false }" class="overflow-hidden rounded-xl border transition {{ $lot->status === 'closed' ? 'border-white/[.04] bg-[#2c2735]/65' : 'border-white/[.06] bg-white/[.025]' }} hover:border-purple-300/15 hover:bg-white/[.04]">
                        <button type="button" x-on:click="expanded = ! expanded" x-bind:aria-expanded="expanded.toString()" aria-controls="{{ $detailId }}" class="grid w-full cursor-pointer gap-3 p-4 text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-cyan-300/60 lg:grid-cols-[minmax(180px,1.35fr)_minmax(145px,1fr)_80px_120px_minmax(175px,1.25fr)_28px] lg:items-center lg:gap-4">
                            <span><span class="block text-sm font-semibold text-white">Инвестиция №{{ $lot->display_number }}</span><span class="mt-1 block text-[11px] font-medium text-cyan-200">{{ $lot->display_source }}</span></span>
                            <span class="flex items-baseline justify-between gap-3 lg:block"><span class="text-[10px] uppercase tracking-wide text-[#81748f] lg:hidden">Текущий капитал</span><span class="font-semibold text-white">{{ MoneyFormatter::format($lot->remaining_amount) }} <span class="text-[10px] font-normal text-[#81758e]">USDT</span></span></span>
                            <span class="flex items-center justify-between gap-3 text-sm lg:block"><span class="text-[10px] uppercase tracking-wide text-[#81748f] lg:hidden">Ставка</span><span>{{ MoneyFormatter::format($lot->monthly_rate) }}%</span></span>
                            <span class="flex items-center justify-between gap-3 text-sm text-[#b1a6bb] lg:block"><span class="text-[10px] uppercase tracking-wide text-[#81748f] lg:hidden">Начисления с</span><span>{{ $lot->accrual_start_date->format('d.m.Y') }}</span></span>
                            <span class="flex items-center justify-between gap-3 text-xs {{ $lot->status === 'closed' ? 'text-[#928799]' : ($lot->is_withdrawable ? 'text-cyan-100' : 'text-purple-100') }}"><span class="lg:hidden">{{ $availability }}</span><span class="hidden lg:inline-flex lg:items-center lg:gap-2">@if($lot->status !== 'closed' && ! $lot->is_withdrawable)<svg viewBox="0 0 24 24" class="size-3.5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>@endif{{ $availability }}</span><svg viewBox="0 0 24 24" class="size-4 shrink-0 transition-transform lg:hidden" x-bind:class="expanded && 'rotate-180'" fill="none" stroke="currentColor" stroke-width="1.8"><path d="m7 10 5 5 5-5"/></svg></span>
                            <svg viewBox="0 0 24 24" class="hidden size-4 transition-transform lg:block" x-bind:class="expanded && 'rotate-180'" fill="none" stroke="currentColor" stroke-width="1.8"><path d="m7 10 5 5 5-5"/></svg>
                        </button>
                        <div x-cloak x-show="expanded" id="{{ $detailId }}" class="border-t border-white/[.05] bg-[#292234]/70 px-4 py-4">
                            <dl class="grid gap-4 text-xs sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                                @foreach([
                                    ['Первоначальная сумма', MoneyFormatter::format($lot->original_amount).' USDT'],
                                    ['Текущий капитал', MoneyFormatter::format($lot->remaining_amount).' USDT'],
                                    ['Источник', $lot->display_source],
                                    ['Ставка', MoneyFormatter::format($lot->monthly_rate).'%'],
                                    ['Начало начислений', $lot->accrual_start_date->format('d.m.Y')],
                                    ['Дата разблокировки', $lot->unlock_date?->format('d.m.Y') ?? 'Без блокировки'],
                                ] as [$label, $value])
                                    <div><dt class="text-[#81748f]">{{ $label }}</dt><dd class="mt-1 font-medium text-[#ddd6e4]">{{ $value }}</dd></div>
                                @endforeach
                            </dl>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </section>

    <section class="rounded-[20px] border border-white/[.07] bg-[#3c354a] p-4 sm:p-6"><h3 class="text-lg font-semibold">История операций</h3><div class="mt-4 space-y-2">
        @forelse($operations as $item)
            @php $incoming=$item['direction']==='in'; @endphp
            <article class="flex items-center gap-3 rounded-2xl bg-white/[.03] px-3 py-3 sm:px-4"><span class="flex size-10 shrink-0 items-center justify-center rounded-xl {{ $incoming ? 'bg-cyan-300/[.07] text-cyan-200' : ($item['direction']==='out' ? 'bg-purple-300/[.08] text-purple-200' : 'bg-white/[.05] text-[#a99db5]') }}"><svg viewBox="0 0 24 24" class="size-[18px]" fill="none" stroke="currentColor" stroke-width="1.7"><path d="{{ $incoming ? 'M12 3v15m-5-5 5 5 5-5' : ($item['direction']==='out' ? 'M12 21V6m5 5-5-5-5 5' : 'M4 12h16') }}"/></svg></span><div class="min-w-0 flex-1"><p class="truncate text-sm font-medium">{{ InvestorPresentation::type($item['type']) }}</p>@if($item['detail'])<p class="mt-0.5 text-[11px] text-cyan-200">{{ $item['detail'] }}</p>@endif<p class="mt-0.5 text-xs text-[#9183a2]">{{ $item['date']->format('d.m.Y') }} · {{ InvestorPresentation::status($item['status']) }}</p></div><p class="shrink-0 text-right text-sm font-semibold">{{ $incoming ? '+' : ($item['direction']==='out' ? '−' : '') }}{{ MoneyFormatter::format($item['amount']) }} <span class="hidden text-[10px] font-normal text-[#81748e] sm:inline">USDT</span></p></article>
        @empty<p class="text-sm text-[#9183a2]">Операций пока нет.</p>@endforelse
    </div></section>
</div>
