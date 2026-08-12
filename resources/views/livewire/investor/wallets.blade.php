<div x-data="{ addOpen: false, archiveId: null, closeAdd() { this.addOpen = false; this.$wire.cancelAdd() } }" x-on:wallet-added.window="addOpen = false" x-on:keydown.escape.window="archiveId ? archiveId = null : (addOpen && closeAdd())" class="mx-auto max-w-[1200px] space-y-5">
    @if(session('success'))<div class="rounded-2xl border border-emerald-300/20 bg-emerald-400/10 px-4 py-3 text-sm text-emerald-200">{{ session('success') }}</div>@endif

    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div><h2 class="text-2xl font-semibold">Кошельки</h2><p class="mt-1 text-sm text-[#9587a7]">Адреса для получения выводов</p></div>
        <button type="button" x-on:click="addOpen = true; $nextTick(() => $refs.walletLabel.focus())" class="cursor-pointer rounded-xl bg-gradient-to-r from-[#8d61ed] to-[#4ebdd5] px-4 py-2.5 text-sm font-semibold text-white transition hover:brightness-110 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cyan-300/60">+ Добавить кошелёк</button>
    </div>

    <div class="grid gap-3 sm:grid-cols-3">
        @foreach([['Всего кошельков', $walletSummary['total']], ['Одобрено', $walletSummary['approved']], ['На проверке', $walletSummary['pending']]] as [$label, $value])
            <div class="rounded-[16px] border border-white/[.07] bg-[#3c354a] px-4 py-3"><p class="text-xs text-[#9789a9]">{{ $label }}</p><p class="mt-1 text-lg font-semibold">{{ $value }}</p></div>
        @endforeach
    </div>

    <section class="rounded-[20px] border border-white/[.07] bg-[#3c354a] p-4 sm:p-6">
        <h3 class="font-semibold">Мои кошельки</h3>
        @if($wallets->isEmpty())
            <div class="mt-4 rounded-2xl border border-white/[.05] bg-white/[.025] px-5 py-8 text-center"><p class="font-medium">У вас пока нет кошельков.</p><p class="mt-1 text-xs text-[#9183a2]">Добавьте адрес, на который будут выполняться выплаты.</p><button type="button" x-on:click="addOpen = true; $nextTick(() => $refs.walletLabel.focus())" class="mt-4 cursor-pointer rounded-xl bg-purple-400/15 px-4 py-2 text-sm text-purple-100 hover:bg-purple-400/20">+ Добавить кошелёк</button></div>
        @else
            <div class="mt-5 hidden grid-cols-[minmax(170px,1.3fr)_75px_90px_minmax(180px,1.5fr)_110px_24px] gap-4 px-4 text-[10px] uppercase tracking-[.07em] text-[#81748f] lg:grid"><span>Название</span><span>Валюта</span><span>Сеть</span><span>Адрес</span><span>Статус</span><span></span></div>
            <div class="mt-2 space-y-2">
                @foreach($wallets as $wallet)
                    @php
                        $title = $wallet->label ?: $wallet->currency.' · '.$wallet->network;
                        $shortAddress = mb_strlen($wallet->address) > 14 ? mb_substr($wallet->address, 0, 7).'…'.mb_substr($wallet->address, -4) : $wallet->address;
                        $statusLabel = match($wallet->status) { 'approved' => 'Одобрен', 'pending' => 'На проверке', 'archived' => 'Архивирован', 'blocked' => 'Заблокирован', default => '—' };
                        $statusClass = match($wallet->status) { 'approved' => 'bg-emerald-300/10 text-emerald-300', 'pending' => 'bg-purple-300/10 text-purple-200', default => 'bg-white/[.06] text-[#a698aa]' };
                        $detailId = 'wallet-details-'.$wallet->id;
                    @endphp
                    <article x-data="{ expanded: false, copied: false }" class="overflow-hidden rounded-xl border border-white/[.06] bg-white/[.025] transition hover:border-purple-300/15 hover:bg-white/[.04]">
                        <button type="button" x-on:click="expanded = ! expanded" x-bind:aria-expanded="expanded.toString()" aria-controls="{{ $detailId }}" class="grid w-full cursor-pointer gap-2 p-4 text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-cyan-300/60 lg:grid-cols-[minmax(170px,1.3fr)_75px_90px_minmax(180px,1.5fr)_110px_24px] lg:items-center lg:gap-4">
                            <span class="flex items-center justify-between gap-3"><span class="font-medium text-white">{{ $title }}</span><span class="rounded-full px-2.5 py-1 text-[10px] lg:hidden {{ $statusClass }}">{{ $statusLabel }}</span></span>
                            <span class="text-xs text-[#aaa0b3] lg:text-sm">{{ $wallet->currency }}<span class="lg:hidden"> · {{ $wallet->network }}</span></span>
                            <span class="hidden text-sm text-[#aaa0b3] lg:block">{{ $wallet->network }}</span>
                            <span class="flex items-center justify-between gap-3 font-mono text-xs text-cyan-100"><span>{{ $shortAddress }}</span><span class="flex items-center gap-1 font-sans text-[10px] text-[#81748f] lg:hidden">Подробнее <svg viewBox="0 0 24 24" class="size-4 transition-transform" x-bind:class="expanded && 'rotate-180'" fill="none" stroke="currentColor" stroke-width="1.8"><path d="m7 10 5 5 5-5"/></svg></span></span>
                            <span class="hidden rounded-full px-2.5 py-1 text-center text-[10px] lg:inline-block {{ $statusClass }}">{{ $statusLabel }}</span>
                            <svg viewBox="0 0 24 24" class="hidden size-4 transition-transform lg:block" x-bind:class="expanded && 'rotate-180'" fill="none" stroke="currentColor" stroke-width="1.8"><path d="m7 10 5 5 5-5"/></svg>
                        </button>
                        <div x-cloak x-show="expanded" id="{{ $detailId }}" class="border-t border-white/[.05] bg-[#292234]/70 px-4 py-4">
                            <dl class="grid gap-4 text-xs sm:grid-cols-2 lg:grid-cols-4">
                                <div><dt class="text-[#81748f]">Название</dt><dd class="mt-1 font-medium text-[#ddd6e4]">{{ $title }}</dd></div>
                                <div><dt class="text-[#81748f]">Валюта</dt><dd class="mt-1 font-medium text-[#ddd6e4]">{{ $wallet->currency }}</dd></div>
                                <div><dt class="text-[#81748f]">Сеть</dt><dd class="mt-1 font-medium text-[#ddd6e4]">{{ $wallet->network }}</dd></div>
                                <div><dt class="text-[#81748f]">Статус</dt><dd class="mt-1 font-medium text-[#ddd6e4]">{{ $statusLabel }}</dd></div>
                                <div><dt class="text-[#81748f]">Дата добавления</dt><dd class="mt-1 font-medium text-[#ddd6e4]">{{ $wallet->created_at->format('d.m.Y H:i') }}</dd></div>
                                <div class="sm:col-span-2 lg:col-span-3"><dt class="text-[#81748f]">Полный адрес</dt><dd class="mt-1 flex items-start gap-2"><code class="min-w-0 flex-1 break-all text-[#ddd6e4]">{{ $wallet->address }}</code><button type="button" x-on:click="navigator.clipboard.writeText(@js($wallet->address)); copied = true; setTimeout(() => copied = false, 1500)" class="shrink-0 cursor-pointer rounded-lg border border-cyan-300/15 px-2 py-1 text-cyan-200"><span x-show="! copied">Копировать</span><span x-cloak x-show="copied">Скопировано</span></button></dd></div>
                            </dl>
                            @if($wallet->status === 'approved')<p class="mt-4 rounded-xl border border-white/[.05] bg-white/[.025] px-3 py-2 text-xs text-[#a99cad]">Изменение адреса требует добавления нового кошелька и повторной проверки.</p>@endif
                            @if($wallet->status === 'pending')<button type="button" x-on:click="archiveId = {{ $wallet->id }}" class="mt-4 cursor-pointer text-xs text-rose-300 hover:text-rose-200">Отменить</button>@endif
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </section>

    <div x-cloak x-show="addOpen" role="dialog" aria-modal="true" aria-labelledby="add-wallet-title" class="fixed inset-0 z-50 flex items-center justify-center p-4">
        <button type="button" aria-label="Закрыть" x-on:click="closeAdd()" class="absolute inset-0 cursor-pointer bg-[#171020]/80 backdrop-blur-sm"></button>
        <section class="relative z-10 w-full max-w-lg rounded-[24px] border border-white/[.09] bg-[#342c43] p-5 shadow-2xl sm:p-7">
            <div class="flex items-start justify-between gap-4"><div><h3 id="add-wallet-title" class="text-lg font-semibold">Добавить кошелёк</h3><p class="mt-1 text-xs text-[#9183a2]">Новый адрес для получения средств</p></div><button type="button" x-on:click="closeAdd()" aria-label="Закрыть" class="cursor-pointer rounded-lg p-2 text-[#9b8cab] hover:bg-white/[.05] hover:text-white">✕</button></div>
            <form wire:submit="add" class="mt-5 space-y-4">
                <label class="block text-xs text-[#a99abc]">Название<input x-ref="walletLabel" wire:model="label" class="mt-1 w-full rounded-xl border border-white/10 bg-[#292235] px-3 py-3 text-white focus:border-cyan-300/40 focus:outline-none"></label>@error('label')<p class="-mt-2 text-xs text-rose-300">{{ $message }}</p>@enderror
                <div class="grid gap-3 sm:grid-cols-2"><label class="text-xs text-[#a99abc]">Валюта<input wire:model="currency" class="mt-1 w-full rounded-xl border border-white/10 bg-[#292235] px-3 py-3 text-white focus:border-cyan-300/40 focus:outline-none"></label><label class="text-xs text-[#a99abc]">Сеть<input wire:model="network" class="mt-1 w-full rounded-xl border border-white/10 bg-[#292235] px-3 py-3 text-white focus:border-cyan-300/40 focus:outline-none"></label></div>
                @error('currency')<p class="-mt-2 text-xs text-rose-300">{{ $message }}</p>@enderror @error('network')<p class="-mt-2 text-xs text-rose-300">{{ $message }}</p>@enderror
                <label class="block text-xs text-[#a99abc]">Адрес<input wire:model="address" class="mt-1 w-full rounded-xl border border-white/10 bg-[#292235] px-3 py-3 text-white focus:border-cyan-300/40 focus:outline-none"></label>@error('address')<p class="-mt-2 text-xs text-rose-300">{{ $message }}</p>@enderror
                <p class="rounded-xl border border-purple-300/10 bg-purple-300/[.045] px-3 py-3 text-xs leading-5 text-[#aa9db6]">После добавления кошелёк будет отправлен на проверку. Использовать его для вывода средств можно будет после одобрения администратором.</p>
                <button type="submit" class="w-full cursor-pointer rounded-xl bg-gradient-to-r from-[#8d61ed] to-[#4ebdd5] px-4 py-3 font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50">Добавить кошелёк</button>
            </form>
        </section>
    </div>

    <div x-cloak x-show="archiveId" role="dialog" aria-modal="true" aria-labelledby="archive-wallet-title" class="fixed inset-0 z-[60] flex items-center justify-center p-4">
        <button type="button" aria-label="Закрыть" x-on:click="archiveId = null" class="absolute inset-0 cursor-pointer bg-[#171020]/80 backdrop-blur-sm"></button>
        <section class="relative z-10 w-full max-w-md rounded-[22px] border border-white/[.09] bg-[#342c43] p-6 shadow-2xl"><h3 id="archive-wallet-title" class="text-lg font-semibold">Отменить добавление кошелька?</h3><p class="mt-2 text-sm text-[#9f92aa]">Кошелёк будет удалён из списка ожидающих проверку.</p><div class="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end"><button type="button" x-on:click="archiveId = null" class="cursor-pointer rounded-xl border border-white/[.08] px-4 py-2.5 text-sm">Назад</button><button type="button" x-on:click="$wire.archive(archiveId); archiveId = null" class="cursor-pointer rounded-xl bg-rose-400/15 px-4 py-2.5 text-sm text-rose-200">Отменить добавление</button></div></section>
    </div>
</div>
