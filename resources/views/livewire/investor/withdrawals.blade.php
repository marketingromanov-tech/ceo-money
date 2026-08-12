@php use App\Support\MoneyFormatter; use App\Support\InvestorPresentation; @endphp
<div class="mx-auto max-w-[1500px] space-y-5">
    <div><h2 class="text-2xl font-semibold">Выводы</h2><p class="mt-1 text-sm text-[#9587a7]">История заявок на вывод средств</p></div>

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        @foreach([
            ['Всего заявок', $withdrawalSummary['total']],
            ['Активных', $withdrawalSummary['active']],
            ['Выплачено', $withdrawalSummary['paid']],
            ['Отклонено / отменено', $withdrawalSummary['declined']],
        ] as [$label, $value])
            <div class="rounded-[18px] border border-white/[.07] bg-[#3c354a] p-4"><p class="text-xs text-[#9789a9]">{{ $label }}</p><p class="mt-2 text-xl font-semibold">{{ $value }}</p></div>
        @endforeach
    </div>

    <section class="rounded-[20px] border border-white/[.07] bg-[#3c354a] p-4 sm:p-6">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
            <div><h3 class="font-semibold">Заявки на вывод</h3><p class="mt-1 text-xs text-[#9183a2]">Новые заявки отображаются первыми</p></div>
            <div class="grid gap-2 sm:grid-cols-2">
                <label class="text-[11px] text-[#9183a2]">Тип
                    <select wire:model.live="typeFilter" class="mt-1 w-full cursor-pointer rounded-xl border border-white/[.08] bg-[#2c2538] px-3 py-2 text-xs text-white focus:border-cyan-300/40 focus:outline-none">
                        <option value="">Все</option><option value="dividend">Дивиденды</option><option value="capital">Капитал</option>
                    </select>
                </label>
                <label class="text-[11px] text-[#9183a2]">Статус
                    <select wire:model.live="statusFilter" class="mt-1 w-full cursor-pointer rounded-xl border border-white/[.08] bg-[#2c2538] px-3 py-2 text-xs text-white focus:border-cyan-300/40 focus:outline-none">
                        <option value="">Все статусы</option>
                        @foreach(['new' => 'Новая', 'review' => 'На проверке', 'approved' => 'Одобрена', 'paid' => 'Выплачена', 'cancelled' => 'Отменена', 'rejected' => 'Отклонена'] as $status => $label)
                            <option value="{{ $status }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
            </div>
        </div>

        @if($withdrawals->isEmpty() && $withdrawalSummary['total'] === 0)
            <div class="mt-5 rounded-2xl border border-white/[.05] bg-white/[.025] px-5 py-8 text-center"><p class="font-medium">У вас пока нет заявок на вывод.</p><p class="mt-1 text-xs text-[#9183a2]">Создать заявку можно на странице Обзор.</p><a href="{{ route('dashboard') }}" class="mt-4 inline-flex rounded-xl bg-purple-400/15 px-4 py-2 text-sm text-purple-100 hover:bg-purple-400/20">На обзор</a></div>
        @elseif($withdrawals->isEmpty())
            <p class="mt-5 rounded-xl border border-white/[.05] bg-white/[.025] px-4 py-6 text-center text-sm text-[#9183a2]">По выбранным фильтрам заявок нет.</p>
        @else
            <div class="mt-5 hidden grid-cols-[95px_minmax(145px,1.2fr)_115px_105px_115px_105px_minmax(145px,1.2fr)_24px] gap-3 px-4 text-[10px] uppercase tracking-[.07em] text-[#81748f] lg:grid">
                <span>Дата</span><span>Тип</span><span>Запрошено</span><span>Комиссия</span><span>К выплате</span><span>Статус</span><span>Кошелёк / сеть</span><span></span>
            </div>
            <div class="mt-2 space-y-2">
                @foreach($withdrawals as $item)
                    @php
                        $typeLabel = InvestorPresentation::type($item->type.'_withdrawal');
                        $statusLabel = InvestorPresentation::investorWithdrawalStatus($item->status);
                        $address = $item->wallet_address_snapshot;
                        $shortAddress = $address && mb_strlen($address) > 14 ? mb_substr($address, 0, 6).'…'.mb_substr($address, -4) : $address;
                        $detailId = 'withdrawal-details-'.$item->id;
                        $statusClass = match($item->status) {
                            'paid' => 'bg-emerald-300/10 text-emerald-300',
                            'rejected', 'cancelled' => 'bg-white/[.06] text-[#a698aa]',
                            'approved' => 'bg-cyan-300/[.08] text-cyan-200',
                            default => 'bg-purple-300/10 text-purple-200',
                        };
                    @endphp
                    <article x-data="{ expanded: false }" class="overflow-hidden rounded-xl border border-white/[.06] bg-white/[.025] transition hover:border-purple-300/15 hover:bg-white/[.04]">
                        <button type="button" x-on:click="expanded = ! expanded" x-bind:aria-expanded="expanded.toString()" aria-controls="{{ $detailId }}" class="grid w-full cursor-pointer gap-3 p-4 text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-cyan-300/60 lg:grid-cols-[95px_minmax(145px,1.2fr)_115px_105px_115px_105px_minmax(145px,1.2fr)_24px] lg:items-center">
                            <span class="order-2 text-xs text-[#9183a2] lg:order-none">{{ $item->requested_at->format('d.m.Y') }}</span>
                            <span class="order-1 flex items-center justify-between gap-3 lg:order-none lg:justify-start"><span class="flex items-center gap-2 font-medium text-white"><svg viewBox="0 0 24 24" class="size-4 text-cyan-200" fill="none" stroke="currentColor" stroke-width="1.7"><path d="{{ $item->type === 'capital' ? 'M12 3v15m-5-5 5 5 5-5' : 'M12 3v12m5-5-5 5-5-5M5 21h14' }}"/></svg>{{ $typeLabel }}</span><span class="rounded-full px-2.5 py-1 text-[10px] lg:hidden {{ $statusClass }}">{{ $statusLabel }}</span></span>
                            <span class="order-3 flex justify-between gap-3 text-xs lg:order-none lg:block"><span class="text-[#81748f] lg:hidden">Запрошено</span><span class="font-semibold">{{ MoneyFormatter::format($item->requested_amount) }} <small class="font-normal text-[#81748f]">{{ $item->currency }}</small></span></span>
                            <span class="hidden text-xs text-[#aaa0b3] lg:block">{{ MoneyFormatter::format($item->fee_amount) }} {{ $item->currency }}</span>
                            <span class="order-4 flex justify-between gap-3 text-xs lg:order-none lg:block"><span class="text-[#81748f] lg:hidden">К выплате</span><span class="font-semibold text-cyan-100">{{ MoneyFormatter::format($item->net_amount) }} <small class="font-normal text-[#81748f]">{{ $item->currency }}</small></span></span>
                            <span class="hidden rounded-full px-2.5 py-1 text-center text-[10px] lg:inline-block {{ $statusClass }}">{{ $statusLabel }}</span>
                            <span class="order-5 flex items-center justify-between gap-3 text-xs text-[#a99cad] lg:order-none lg:block lg:truncate"><span>{{ $item->network_snapshot && $shortAddress ? $item->network_snapshot.' · '.$shortAddress : '—' }}</span><span class="flex items-center gap-1 text-[10px] text-[#81748f] lg:hidden">Подробнее <svg viewBox="0 0 24 24" class="size-4 transition-transform" x-bind:class="expanded && 'rotate-180'" fill="none" stroke="currentColor" stroke-width="1.8"><path d="m7 10 5 5 5-5"/></svg></span></span>
                            <svg viewBox="0 0 24 24" class="hidden size-4 transition-transform lg:block" x-bind:class="expanded && 'rotate-180'" fill="none" stroke="currentColor" stroke-width="1.8"><path d="m7 10 5 5 5-5"/></svg>
                        </button>

                        <div x-cloak x-show="expanded" id="{{ $detailId }}" class="border-t border-white/[.05] bg-[#292234]/70 px-4 py-4">
                            <dl class="grid gap-4 text-xs sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                                @foreach([
                                    ['Тип операции', $typeLabel], ['Дата создания заявки', $item->requested_at->format('d.m.Y H:i')],
                                    ['Запрошено', MoneyFormatter::format($item->requested_amount).' '.$item->currency], ['Комиссия', MoneyFormatter::format($item->fee_amount).' '.$item->currency],
                                    ['К выплате', MoneyFormatter::format($item->net_amount).' '.$item->currency], ['Статус', $statusLabel],
                                ] as [$label, $value])
                                    <div><dt class="text-[#81748f]">{{ $label }}</dt><dd class="mt-1 font-medium text-[#ddd6e4]">{{ $value }}</dd></div>
                                @endforeach
                                @if($item->network_snapshot)<div><dt class="text-[#81748f]">Сеть</dt><dd class="mt-1 font-medium text-[#ddd6e4]">{{ $item->network_snapshot }}</dd></div>@endif
                                @if($address)<div class="sm:col-span-2"><dt class="text-[#81748f]">Полный адрес кошелька</dt><dd class="mt-1 flex items-start gap-2"><code class="min-w-0 flex-1 break-all text-[#ddd6e4]">{{ $address }}</code><button type="button" onclick="navigator.clipboard.writeText(@js($address)); this.textContent='Скопировано'" class="shrink-0 cursor-pointer rounded-lg border border-cyan-300/15 px-2 py-1 text-cyan-200">Copy</button></dd></div>@endif
                                @if($item->txid)<div class="sm:col-span-2"><dt class="text-[#81748f]">TXID</dt><dd class="mt-1 flex items-start gap-2"><code class="min-w-0 flex-1 break-all text-[#ddd6e4]">{{ $item->txid }}</code><button type="button" onclick="navigator.clipboard.writeText(@js($item->txid)); this.textContent='Скопировано'" class="shrink-0 cursor-pointer rounded-lg border border-cyan-300/15 px-2 py-1 text-cyan-200">Copy</button></dd></div>@endif
                                @if($item->approved_at)<div><dt class="text-[#81748f]">Дата одобрения</dt><dd class="mt-1 text-[#ddd6e4]">{{ $item->approved_at->format('d.m.Y H:i') }}</dd></div>@endif
                                @if($item->paid_at)<div><dt class="text-[#81748f]">Дата выплаты</dt><dd class="mt-1 text-[#ddd6e4]">{{ $item->paid_at->format('d.m.Y H:i') }}</dd></div>@endif
                                @if($item->cancelled_at)<div><dt class="text-[#81748f]">Дата отмены</dt><dd class="mt-1 text-[#ddd6e4]">{{ $item->cancelled_at->format('d.m.Y H:i') }}</dd></div>@endif
                                @if($item->status === 'rejected' && $item->rejected_reason)<div class="sm:col-span-2"><dt class="text-[#81748f]">Причина отказа</dt><dd class="mt-1 text-[#ddd6e4]">{{ $item->rejected_reason }}</dd></div>@endif
                                @if($item->status === 'cancelled' && $item->cancellation_reason)<div class="sm:col-span-2"><dt class="text-[#81748f]">Причина отмены</dt><dd class="mt-1 text-[#ddd6e4]">{{ $item->cancellation_reason }}</dd></div>@endif
                            </dl>
                        </div>
                    </article>
                @endforeach
            </div>
            @if($withdrawals->hasPages())<div class="mt-5 border-t border-white/[.06] pt-4">{{ $withdrawals->links() }}</div>@endif
        @endif
    </section>
</div>
