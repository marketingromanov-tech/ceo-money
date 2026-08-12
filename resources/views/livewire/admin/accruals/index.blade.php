@php use App\Support\MoneyFormatter; @endphp
<div class="mx-auto max-w-[1600px] space-y-5">
    <div><h1 class="text-2xl font-semibold">Начисления</h1><p class="mt-1 text-sm text-[#9587a7]">Контроль начислений инвесторов</p></div>

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        @foreach([
            ['Начислено сегодня', $kpis['today']], ['Начислено за текущий месяц', $kpis['month']],
            ['Начислено за всё время', $kpis['total']], ['Корректировки за текущий месяц', $kpis['adjustments']],
        ] as [$label, $value])
            <div class="rounded-[16px] border border-white/[.07] bg-[#3c354a] px-4 py-3"><p class="text-xs text-[#9789a9]">{{ $label }}</p><p class="mt-1 text-lg font-semibold {{ str_contains($label, 'Корректировки') ? 'text-purple-100' : 'text-cyan-100' }}">{{ MoneyFormatter::format($value) }} <span class="text-[10px] font-normal text-[#81748f]">USDT</span></p></div>
        @endforeach
    </div>

    <section class="rounded-[18px] border border-white/[.07] bg-[#3c354a] p-4">
        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-[minmax(220px,1.4fr)_180px_180px_190px]">
            <label class="text-[11px] text-[#9183a2]">Поиск по инвестору<input wire:model.live.debounce.300ms="search" placeholder="Имя, email или код" class="mt-1 w-full rounded-xl border border-white/[.08] bg-[#2c2538] px-3 py-2.5 text-sm text-white placeholder:text-[#6f647c] focus:border-cyan-300/40 focus:outline-none"></label>
            <label class="text-[11px] text-[#9183a2]">Инвестор<select wire:model.live="investorId" class="mt-1 w-full cursor-pointer rounded-xl border border-white/[.08] bg-[#2c2538] px-3 py-2.5 text-sm text-white focus:border-cyan-300/40 focus:outline-none"><option value="">Все инвесторы</option>@foreach($investors as $investor)<option value="{{ $investor->id }}">{{ $investor->code }} · {{ $investor->user->name }}</option>@endforeach</select></label>
            <label class="text-[11px] text-[#9183a2]">Инвестиция<select wire:model.live="investmentLotId" @disabled($investorId === '') class="mt-1 w-full cursor-pointer rounded-xl border border-white/[.08] bg-[#2c2538] px-3 py-2.5 text-sm text-white focus:border-cyan-300/40 focus:outline-none disabled:cursor-not-allowed disabled:opacity-50"><option value="">Все инвестиции</option>@foreach($investmentOptions as $index => $lot)<option value="{{ $lot->id }}">Инвестиция №{{ $index + 1 }}</option>@endforeach</select></label>
            <label class="text-[11px] text-[#9183a2]">Корректировки<select wire:model.live="adjustmentFilter" class="mt-1 w-full cursor-pointer rounded-xl border border-white/[.08] bg-[#2c2538] px-3 py-2.5 text-sm text-white focus:border-cyan-300/40 focus:outline-none"><option value="all">Все</option><option value="with">С корректировками</option><option value="without">Без корректировок</option></select></label>
        </div>
        <div class="mt-4 flex max-w-full gap-1 overflow-x-auto rounded-xl bg-[#2c2538] p-1 sm:w-fit">
            @foreach(['today'=>'Сегодня', 'month'=>'Этот месяц', 'all'=>'Всё время', 'custom'=>'Произвольный период'] as $value => $label)<button type="button" wire:click="selectPeriod('{{ $value }}')" class="shrink-0 cursor-pointer rounded-lg px-3 py-2 text-xs {{ $period === $value ? 'bg-purple-400/15 text-purple-100' : 'text-[#9183a2] hover:bg-white/[.04]' }}">{{ $label }}</button>@endforeach
        </div>
        @if($period === 'custom')
            <div class="mt-3 grid gap-2 sm:grid-cols-[160px_160px_auto_auto] sm:items-end"><label class="text-[11px] text-[#9183a2]">Дата From<input type="date" wire:model="dateFrom" class="mt-1 w-full rounded-xl border border-white/[.08] bg-[#2c2538] px-3 py-2 text-xs text-white"></label><label class="text-[11px] text-[#9183a2]">Дата To<input type="date" wire:model="dateTo" class="mt-1 w-full rounded-xl border border-white/[.08] bg-[#2c2538] px-3 py-2 text-xs text-white"></label><button type="button" wire:click="applyCustomPeriod" class="cursor-pointer rounded-xl bg-purple-400/15 px-4 py-2 text-xs text-purple-100">Применить</button><button type="button" wire:click="resetFilters" class="cursor-pointer rounded-xl border border-white/[.08] px-4 py-2 text-xs text-[#aaa0b3]">Сбросить</button></div>
            @error('dateFrom')<p class="mt-2 text-xs text-rose-300">{{ $message }}</p>@enderror @error('dateTo')<p class="mt-2 text-xs text-rose-300">{{ $message }}</p>@enderror
        @else
            <button type="button" wire:click="resetFilters" class="mt-3 cursor-pointer text-xs text-[#9d8faf] hover:text-white">Сбросить фильтры</button>
        @endif
    </section>

    <section class="rounded-[18px] border border-white/[.07] bg-[#3c354a] p-4 sm:p-5">
        <div class="hidden grid-cols-[90px_minmax(170px,1.4fr)_115px_135px_80px_105px_105px_105px_22px] gap-3 px-3 pb-3 text-[9px] uppercase tracking-[.07em] text-[#81748f] lg:grid"><span>Дата</span><span>Инвестор</span><span>Инвестиция</span><span>Сумма инвестиции</span><span>Ставка</span><span>Начислено</span><span>Корректировка</span><span>Итого</span><span></span></div>
        <div class="space-y-2">
            @forelse($accruals as $item)
                @php
                    $investor = $item->investmentAccount->investor;
                    $lot = $item->investmentLot;
                    $investmentNumber = $lotNumbers[$lot->id];
                    $source = $lot->dividendCapitalization ? 'Капитализация дивидендов' : 'Пополнение';
                @endphp
                <article x-data="{ expanded: false }" class="overflow-hidden rounded-xl border border-white/[.055] bg-white/[.025] transition hover:border-purple-300/15 hover:bg-white/[.04]">
                    <button type="button" x-on:click="expanded = ! expanded" x-bind:aria-expanded="expanded.toString()" aria-controls="accrual-details-{{ $item->id }}" class="grid w-full cursor-pointer gap-2 p-3 text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-cyan-300/60 lg:grid-cols-[90px_minmax(170px,1.4fr)_115px_135px_80px_105px_105px_105px_22px] lg:items-center lg:gap-3">
                        <span class="text-xs text-[#aaa0b3]">{{ $item->accrual_date->format('d.m.Y') }}</span>
                        <span><span class="block truncate text-sm font-medium text-white">{{ $investor->user->name }}</span><span class="block truncate text-[10px] text-[#81748f]">{{ $investor->code }}</span></span>
                        <span class="text-xs text-purple-100">Инвестиция №{{ $investmentNumber }}</span>
                        <span class="flex justify-between text-xs lg:block"><span class="text-[#81748f] lg:hidden">Сумма инвестиции</span><span>{{ MoneyFormatter::format($item->principal_amount) }} USDT</span></span>
                        <span class="hidden text-xs text-cyan-200 lg:block">{{ MoneyFormatter::format($item->monthly_rate) }}%</span>
                        <span class="hidden text-xs lg:block">{{ MoneyFormatter::format($item->calculated_amount) }}</span>
                        <span class="hidden text-xs {{ $item->adjustment_amount !== '0.00000000' ? 'text-purple-200' : 'text-[#81748f]' }} lg:block">{{ MoneyFormatter::format($item->adjustment_amount) }}</span>
                        <span class="flex items-center justify-between text-xs font-semibold text-cyan-100"><span class="text-[#81748f] lg:hidden">Итого</span><span>{{ MoneyFormatter::format($item->final_amount) }} USDT</span></span>
                        <span class="flex items-center justify-end gap-1 text-[10px] text-[#81748f] lg:block"><span class="lg:hidden">Подробнее</span><svg viewBox="0 0 24 24" class="size-4 transition-transform" x-bind:class="expanded && 'rotate-180'" fill="none" stroke="currentColor"><path d="m7 10 5 5 5-5"/></svg></span>
                    </button>
                    <div x-cloak x-show="expanded" id="accrual-details-{{ $item->id }}" class="border-t border-white/[.05] bg-[#292234]/70 px-4 py-4"><dl class="grid gap-4 text-xs sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-6">
                        @foreach([
                            ['Инвестор', $investor->user->name], ['Email', $investor->user->email], ['Код инвестора', $investor->code ?: '—'], ['Инвестиция', 'Инвестиция №'.$investmentNumber],
                            ['Источник инвестиции', $source], ['Первоначальная сумма', MoneyFormatter::format($lot->original_amount).' USDT'], ['Сумма инвестиции', MoneyFormatter::format($item->principal_amount).' USDT'],
                            ['Ставка', MoneyFormatter::format($item->monthly_rate).'%'], ['Начало начислений', $lot->accrual_start_date->format('d.m.Y')], ['Дата начисления', $item->accrual_date->format('d.m.Y')],
                            ['Базовое начисление', MoneyFormatter::format($item->calculated_amount).' USDT'], ['Корректировка', MoneyFormatter::format($item->adjustment_amount).' USDT'], ['Итоговое начисление', MoneyFormatter::format($item->final_amount).' USDT'],
                        ] as [$label, $value])<div><dt class="text-[#81748f]">{{ $label }}</dt><dd class="mt-1 font-medium text-[#ddd6e4]">{{ $value }}</dd></div>@endforeach
                    </dl></div>
                </article>
            @empty
                <p class="rounded-xl border border-white/[.05] bg-white/[.025] px-4 py-8 text-center text-sm text-[#9183a2]">Начислений по выбранным условиям нет.</p>
            @endforelse
        </div>
        @if($accruals->hasPages())<div class="mt-5 border-t border-white/[.06] pt-4">{{ $accruals->links() }}</div>@endif
    </section>
</div>
