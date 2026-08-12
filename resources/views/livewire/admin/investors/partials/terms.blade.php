<section class="mt-4 space-y-4">
    @if (session('status'))
        <div class="rounded-2xl border border-cyan-300/20 bg-cyan-300/[.07] px-4 py-3 text-xs text-cyan-200">{{ session('status') }}</div>
    @endif

    <article class="overflow-hidden rounded-[20px] border border-white/[.07] bg-[#3c354a]">
        <header class="flex flex-col gap-3 border-b border-white/[.06] bg-[#332c42] px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
            <div><h3 class="text-sm font-semibold text-white">Текущие условия</h3><p class="mt-1 text-[10px] text-[#9080ba]">Условия инвестиционного счёта, действующие сегодня</p></div>
            <button type="button" wire:click="openTermsModal" class="cursor-pointer rounded-xl bg-gradient-to-r from-[#ac6aec] to-[#7a72e8] px-4 py-2.5 text-xs font-semibold text-white transition hover:brightness-110">Изменить условия</button>
        </header>
        @if($currentTerm)
            <dl class="grid gap-px bg-white/[.05] sm:grid-cols-2 xl:grid-cols-4">
                @php
                    $currentItems = [
                        ['Ставка в месяц', \App\Support\InvestmentTermPresentation::rate($currentTerm->monthly_rate)],
                        ['Капитал доступен к выводу через', \App\Support\InvestmentTermPresentation::lockMonths($currentTerm->lock_months)],
                        ['Минимальный остаток', \App\Support\MoneyFormatter::format($currentTerm->minimum_balance).' USDT'],
                        ['Частичный вывод', $currentTerm->partial_withdrawal_allowed ? 'Разрешён' : 'Запрещён'],
                        ['Минимальный вывод дивидендов', \App\Support\MoneyFormatter::format($currentTerm->minimum_dividend_withdrawal).' USDT'],
                        ['Действуют с', $currentTerm->valid_from->format('d.m.Y')],
                        ['Действуют по', $currentTerm->valid_to?->format('d.m.Y') ?? 'Бессрочно'],
                    ];
                @endphp
                @foreach($currentItems as [$label, $value])<div class="bg-[#3c354a] px-5 py-4"><dt class="text-[10px] text-[#9080ba]">{{ $label }}</dt><dd class="mt-1.5 text-sm font-medium text-white">{{ $value }}</dd></div>@endforeach
            </dl>
        @else
            <div class="px-5 py-10 text-center text-xs text-[#9080ba]">На сегодня действующие условия не заданы.</div>
        @endif
    </article>

    <article class="overflow-hidden rounded-[20px] border border-white/[.07] bg-[#3c354a]">
        <header class="border-b border-white/[.06] bg-[#332c42] px-5 py-4"><h3 class="text-sm font-semibold text-white">История условий</h3><p class="mt-1 text-[10px] text-[#9080ba]">Все прошлые и запланированные условия без удаления истории</p></header>
        <div class="divide-y divide-white/[.05]">
            @forelse($terms as $term)
                @php
                    $today = \Carbon\Carbon::today();
                    $status = \App\Support\InvestmentTermPresentation::status($term, $today);
                    $statusClass = $status === 'Действует' ? 'bg-emerald-400/10 text-emerald-300' : ($status === 'Будет действовать' ? 'bg-cyan-300/10 text-cyan-200' : 'bg-white/[.06] text-[#a99db6]');
                @endphp
                <div x-data="{ open: false }" class="bg-[#3a3348]/45 transition hover:bg-white/[.025]">
                    <button type="button" @click="open = !open" class="grid w-full cursor-pointer grid-cols-2 items-center gap-3 px-5 py-4 text-left text-xs md:grid-cols-[1.3fr_.7fr_.7fr_1fr_.8fr_1fr_.8fr_auto]">
                        <span class="col-span-2 text-white md:col-span-1">{{ $term->valid_from->format('d.m.Y') }} — {{ $term->valid_to?->format('d.m.Y') ?? 'Бессрочно' }}</span>
                        <span class="text-cyan-300">{{ \App\Support\InvestmentTermPresentation::rate($term->monthly_rate) }}</span>
                        <span>{{ $term->lock_months }} мес.</span><span class="hidden md:block">{{ \App\Support\MoneyFormatter::format($term->minimum_balance) }} USDT</span>
                        <span class="hidden md:block">{{ $term->partial_withdrawal_allowed ? 'Разрешён' : 'Запрещён' }}</span><span class="hidden md:block">{{ \App\Support\MoneyFormatter::format($term->minimum_dividend_withdrawal) }} USDT</span>
                        <span><span class="rounded-full px-2 py-1 text-[9px] {{ $statusClass }}">{{ $status }}</span></span><span class="justify-self-end text-[#9c8cab] transition" :class="open && 'rotate-180'">⌄</span>
                    </button>
                    <div x-show="open" x-cloak class="grid gap-3 border-t border-white/[.05] bg-[#332c42]/70 px-5 py-4 text-[10px] text-[#9e91ad] sm:grid-cols-3">
                        <p>Создал: <span class="text-white">{{ $term->creator?->name ?? 'Система' }}</span></p><p>Создано: <span class="text-white">{{ $term->created_at->format('d.m.Y H:i') }}</span></p><p>ID правила: <span class="text-white">{{ $term->id }}</span></p>
                        <p>Минимальный остаток: <span class="text-white">{{ \App\Support\MoneyFormatter::format($term->minimum_balance) }} USDT</span></p><p>Частичный вывод: <span class="text-white">{{ $term->partial_withdrawal_allowed ? 'Разрешён' : 'Запрещён' }}</span></p><p>Минимум дивидендов: <span class="text-white">{{ \App\Support\MoneyFormatter::format($term->minimum_dividend_withdrawal) }} USDT</span></p>
                    </div>
                </div>
            @empty<div class="px-5 py-10 text-center text-xs text-[#9080ba]">История условий пока пуста.</div>@endforelse
        </div>
    </article>

    @if($showTermsModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4" role="dialog" aria-modal="true">
            <button type="button" wire:click="closeTermsModal" class="absolute inset-0 cursor-pointer bg-[#171020]/80 backdrop-blur-sm" aria-label="Закрыть"></button>
            <form wire:submit="saveTerms" class="relative z-10 max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-[24px] border border-white/[.1] bg-[#3c354a] shadow-2xl shadow-purple-950/50">
                <header class="flex items-start justify-between border-b border-white/[.07] bg-[#332c42] px-5 py-4 sm:px-6"><div><h3 class="text-base font-semibold">Новые условия</h3><p class="mt-1 text-[10px] text-[#9080ba]">Новая версия условий без изменения истории инвестиций</p></div><button type="button" wire:click="closeTermsModal" class="cursor-pointer rounded-xl p-2 text-[#a89ab5] hover:bg-white/[.06] hover:text-white">✕</button></header>
                <div class="grid gap-4 p-5 sm:grid-cols-2 sm:p-6">
                    @foreach([['termMonthlyRate','Ставка в месяц (%)','0.0000'],['termLockMonths','Капитал доступен к выводу через (месяцев)','0'],['termMinimumBalance','Минимальный остаток (USDT)','0.00000000'],['termMinimumDividendWithdrawal','Минимальная сумма вывода дивидендов (USDT)','0.00000000'],['termValidFrom','Действует с','']] as [$model,$label,$placeholder])
                        <label class="block {{ $model === 'termValidFrom' ? 'sm:col-span-2' : '' }}"><span class="text-[10px] text-[#a99db6]">{{ $label }}</span><input wire:model="{{ $model }}" type="{{ $model === 'termValidFrom' ? 'date' : 'text' }}" inputmode="{{ $model === 'termValidFrom' ? 'none' : 'decimal' }}" placeholder="{{ $placeholder }}" class="mt-2 h-12 w-full rounded-xl border border-white/[.09] bg-[#2f293e] px-3 text-sm text-white outline-none transition focus:border-cyan-300/50 focus:ring-2 focus:ring-[#ac6aec]/20">@error($model)<span class="mt-1 block text-[10px] text-red-300">{{ $message }}</span>@enderror</label>
                    @endforeach
                    <label class="flex cursor-pointer items-center gap-3 sm:col-span-2"><input wire:model="termPartialWithdrawalAllowed" type="checkbox" class="size-4 rounded border-white/20 bg-[#2f293e] text-[#ac6aec] focus:ring-[#ac6aec]"><span class="text-xs text-white">Разрешить частичный вывод капитала</span></label>
                    <div class="sm:col-span-2 rounded-2xl border border-cyan-300/15 bg-cyan-300/[.055] p-4 text-[11px] leading-5 text-[#b9cbd1]">Новые условия применяются к новым инвестициям. Ставка и дата разблокировки уже созданных инвестиций остаются неизменными; осознанное изменение ставки отдельной инвестиции возможно только отдельным lot-level условием.</div>
                </div>
                <footer class="flex justify-end gap-3 border-t border-white/[.07] px-5 py-4 sm:px-6"><button type="button" wire:click="closeTermsModal" class="cursor-pointer rounded-xl border border-white/[.09] px-4 py-2.5 text-xs text-[#b0a3bd] hover:bg-white/[.04]">Отмена</button><button type="submit" class="cursor-pointer rounded-xl bg-gradient-to-r from-[#ac6aec] to-[#727ce7] px-5 py-2.5 text-xs font-semibold text-white hover:brightness-110 disabled:cursor-not-allowed" wire:loading.attr="disabled">Сохранить условия</button></footer>
            </form>
        </div>
    @endif
</section>
