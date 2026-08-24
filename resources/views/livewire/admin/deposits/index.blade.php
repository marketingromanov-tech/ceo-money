@php
    use App\Support\FeeRulePresentation;
    use App\Support\InvestorPresentation;
    use App\Support\MoneyFormatter;
@endphp

<div>
    <div class="mb-7">
        <p class="text-sm font-medium text-purple-300">Операции</p>
        <h1 class="mt-1 text-3xl font-semibold">Пополнения</h1>
        <p class="mt-2 text-sm text-[#8f829f]">Заявки на внесение инвестиционного капитала</p>
    </div>

    @if (session('success'))
        <div class="mb-4 rounded-xl bg-emerald-300/10 p-3 text-sm text-emerald-200">{{ session('success') }}</div>
    @endif

    <div class="mb-4 flex justify-end">
        <select wire:model.live="status" class="w-full cursor-pointer rounded-xl border border-white/[.09] bg-[#3c354a] px-4 py-2.5 text-sm focus:border-cyan-300/40 focus:outline-none sm:w-56">
            <option value="">Все статусы</option>
            @foreach (['pending', 'payment_submitted', 'submitted', 'confirmed', 'rejected', 'cancelled'] as $value)
                <option value="{{ $value }}">{{ InvestorPresentation::adminDepositStatus($value) }}</option>
            @endforeach
        </select>
    </div>

    <div class="hidden overflow-hidden rounded-[18px] border border-white/[.07] bg-[#3c354a] md:block">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[1150px] text-left text-sm">
                <thead class="border-b border-white/[.07] bg-[#332c42] text-[10px] uppercase text-[#9285a0]">
                    <tr>
                        @foreach (['Инвестор', 'Программа', 'Сумма', 'Валюта', 'Сеть', 'Адрес', 'TXID', 'Статус', 'Дата', 'Действие'] as $heading)
                            <th class="px-4 py-4 font-medium">{{ $heading }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/[.05]">
                    @forelse ($deposits as $item)
                        @php
                            $address = data_get($item->payment_details_snapshot, 'address', $item->deposit_address_snapshot);
                            $network = data_get($item->payment_details_snapshot, 'network', $item->network);
                            $programName = data_get($item->investment_program_snapshot, 'name', '—');
                            $shortAddress = $address && mb_strlen($address) > 12 ? mb_substr($address, 0, 6).'…'.mb_substr($address, -4) : $address;
                            $shortTxid = $item->txid && mb_strlen($item->txid) > 16 ? mb_substr($item->txid, 0, 8).'…'.mb_substr($item->txid, -6) : $item->txid;
                            $action = ['pending' => 'Взять в обработку', 'payment_submitted' => 'Проверить оплату', 'submitted' => 'Открыть проверку'][$item->status] ?? null;
                        @endphp
                        <tr class="bg-[#3a3348]/45 transition hover:bg-white/[.04]">
                            <td class="px-4 py-4 font-medium">{{ $item->investor->user->name }}</td>
                            <td class="px-4 py-4 text-xs text-purple-100">{{ $programName }}</td>
                            <td class="px-4 py-4 font-medium">{{ MoneyFormatter::format($item->requested_amount) }} {{ $item->currency }}</td>
                            <td class="px-4 py-4">{{ $item->currency }}</td>
                            <td class="px-4 py-4">{{ $network ?: '—' }}</td>
                            <td class="max-w-40 px-4 py-4 font-mono text-xs" title="{{ $address }}">{{ $shortAddress ?: '—' }}</td>
                            <td class="max-w-40 px-4 py-4 font-mono text-xs" title="{{ $item->txid }}">{{ $shortTxid ?: '—' }}</td>
                            <td class="px-4 py-4"><span class="rounded-full bg-purple-300/10 px-2.5 py-1 text-[10px] text-purple-100">{{ InvestorPresentation::adminDepositStatus($item->status) }}</span>@if($item->status !== 'pending')<p class="mt-2 text-[10px] text-[#9080ba]">Проверка: {{ $item->verification_passed_count }}/{{ count(\App\Services\DepositVerificationService::KEYS) }}</p>@endif</td>
                            <td class="px-4 py-4 text-xs text-[#9285a0]">{{ $item->requested_at->format('d.m.Y H:i') }}</td>
                            <td class="px-4 py-4">
                                @if ($action)
                                    <button type="button" wire:click="openAction({{ $item->id }})" class="cursor-pointer rounded-lg bg-cyan-300/10 px-3 py-2 text-xs text-cyan-100 hover:bg-cyan-300/15">{{ $action }}</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="p-12 text-center text-[#9285a0]">Заявок нет</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($deposits->hasPages()) <div class="border-t border-white/[.07] px-5 py-4">{{ $deposits->links() }}</div> @endif
    </div>

    <div class="space-y-3 md:hidden">
        @forelse ($deposits as $item)
            @php $action = ['pending' => 'Взять в обработку', 'payment_submitted' => 'Проверить оплату', 'submitted' => 'Открыть проверку'][$item->status] ?? null; @endphp
            <article class="rounded-[18px] border border-white/[.07] bg-[#3c354a] p-4">
                <div class="flex justify-between gap-2"><div><h3 class="font-semibold">{{ $item->investor->user->name }}</h3><p class="text-xs text-purple-200">{{ data_get($item->investment_program_snapshot,'name','Пополнение') }}</p></div><div class="text-right"><span class="text-xs text-cyan-200">{{ InvestorPresentation::adminDepositStatus($item->status) }}</span>@if($item->status !== 'pending')<p class="mt-1 text-[10px] text-[#9080ba]">Проверено {{ $item->verification_passed_count }}/{{ count(\App\Services\DepositVerificationService::KEYS) }}</p>@endif</div></div>
                <p class="mt-3 font-semibold">{{ MoneyFormatter::format($item->requested_amount) }} {{ $item->currency }}</p>
                <p class="text-xs text-[#aaa0b3]">Комиссия: {{ MoneyFormatter::format($item->fee_amount) }} {{ $item->currency }}</p>
                <p class="text-xs text-[#aaa0b3]">В инвестицию: {{ $item->net_investment_amount ? MoneyFormatter::format($item->net_investment_amount).' '.$item->currency : 'будет рассчитано' }}</p>
                @if ($action) <button type="button" wire:click="openAction({{ $item->id }})" class="mt-4 w-full cursor-pointer rounded-xl bg-cyan-300/10 py-2.5 text-sm text-cyan-100">{{ $action }}</button> @endif
            </article>
        @empty
            <div class="p-8 text-center text-[#9285a0]">Заявок нет</div>
        @endforelse
    </div>

    @if ($showActionModal && $selectedDeposit)
        <div x-data="{ visible: true }" x-show="visible" x-on:keydown.escape.window="visible = false; $wire.closeAction()" class="fixed inset-0 z-50 flex items-center justify-center bg-[#171020]/80 p-3 backdrop-blur-sm" role="dialog" aria-modal="true">
            <button type="button" aria-label="Закрыть" x-on:click="visible = false; $wire.closeAction()" class="absolute inset-0 cursor-default"></button>
            <section class="relative max-h-[94vh] w-full max-w-2xl overflow-hidden rounded-[22px] border border-white/[.09] bg-[#342c43] shadow-2xl">
                <header class="flex items-start justify-between border-b border-white/[.07] p-5">
                    <div><h2 class="text-lg font-semibold">Обработка заявки на пополнение</h2><p class="text-xs text-[#9080ba]">Заявка #{{ $selectedDeposit->id }}</p></div>
                    <button type="button" x-on:click="visible = false; $wire.closeAction()" class="cursor-pointer rounded-lg p-2 text-[#aaa0b3] hover:bg-white/[.05] hover:text-white">✕</button>
                </header>
                <div class="max-h-[76vh] overflow-y-auto p-5">
                    @error('action') <div class="mb-4 rounded-xl bg-rose-300/10 p-3 text-sm text-rose-200">{{ $message }}</div> @enderror

                    @php
                        $received = $selectedDeposit->received_amount ?? $selectedDeposit->requested_amount;
                        $shownFee = $preview['fee_amount'] ?? $selectedDeposit->fee_amount;
                        $shownNet = $preview['net_amount'] ?? $selectedDeposit->net_investment_amount;
                        $paymentAddress = data_get($selectedDeposit->payment_details_snapshot, 'address', $selectedDeposit->deposit_address_snapshot);
                        $paymentNetwork = data_get($selectedDeposit->payment_details_snapshot, 'network', $selectedDeposit->network);
                        $paymentMemo = data_get($selectedDeposit->payment_details_snapshot, 'memo');
                        $programName = data_get($selectedDeposit->investment_program_snapshot, 'name', '—');
                    @endphp

                    @if ($selectedDeposit->status === 'submitted')
                        @php $preflightTerm = $confirmationPreflight['term'] ?? null; $effectiveTerms = $confirmationPreflight['effective_terms'] ?? null; @endphp
                        <div class="mb-5 rounded-xl border {{ ($preflightTerm||$effectiveTerms) ? 'border-cyan-300/15 bg-cyan-300/[.05]' : 'border-amber-300/20 bg-amber-300/[.06]' }} p-4">
                            <h3 class="text-sm font-semibold">Условия новой инвестиции</h3>
                            @if ($effectiveTerms)
                                <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-3"><div><dt class="text-xs text-[#9080ba]">Источник</dt><dd class="mt-1 text-white">{{ $effectiveTerms['source']==='individual'?'Индивидуальные условия':'Инвестиционная программа' }}</dd></div><div><dt class="text-xs text-[#9080ba]">Ставка</dt><dd class="mt-1 text-white">{{ \App\Support\InvestmentTermPresentation::rate($effectiveTerms['rate']) }} в месяц</dd></div><div><dt class="text-xs text-[#9080ba]">Срок</dt><dd class="mt-1 text-white">{{ $effectiveTerms['term_months'] }} мес.</dd></div></dl>
                            @elseif ($preflightTerm)
                                <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-3">
                                    <div><dt class="text-xs text-[#9080ba]">Ставка</dt><dd class="mt-1 text-white">{{ \App\Support\InvestmentTermPresentation::rate($preflightTerm->monthly_rate) }} в месяц</dd></div>
                                    <div><dt class="text-xs text-[#9080ba]">Капитал доступен к выводу через</dt><dd class="mt-1 text-white">{{ \App\Support\InvestmentTermPresentation::lockMonths($preflightTerm->lock_months) }}</dd></div>
                                    <div><dt class="text-xs text-[#9080ba]">Начало начислений</dt><dd class="mt-1 text-white">{{ $confirmationPreflight['date']->format('d.m.Y') }}</dd></div>
                                </dl>
                            @else
                                <p class="mt-2 text-sm leading-relaxed text-amber-100">Для инвестора не настроены действующие условия инвестирования. Перед подтверждением пополнения необходимо указать ставку и срок доступности капитала.</p>
                                <a href="{{ route('admin.investors.show', $selectedDeposit->investor) }}?tab=terms" wire:navigate class="mt-3 inline-flex cursor-pointer rounded-xl bg-purple-300/15 px-4 py-2.5 text-sm text-purple-100 hover:bg-purple-300/20">Настроить условия инвестора</a>
                            @endif
                        </div>
                    @endif

                    @if ($verificationSummary && $selectedDeposit->status !== 'pending')
                        @php
                            $terminalVerification = in_array($selectedDeposit->status, ['confirmed', 'rejected', 'cancelled'], true);
                            $isBingx = str_contains(mb_strtolower((string) $selectedDeposit->provider_snapshot), 'bingx');
                        @endphp
                        <section class="mb-5 rounded-xl border border-white/[.08] bg-[#2d263a] p-4">
                            <div class="flex flex-wrap items-center justify-between gap-2"><div><p class="text-[10px] uppercase tracking-wider text-[#9080ba]">Шаг 2</p><h3 class="text-sm font-semibold">Проверка поступления</h3></div><span class="rounded-full bg-purple-300/10 px-3 py-1 text-xs text-purple-100">Проверено {{ $verificationSummary['passed'] }}/{{ $verificationSummary['total'] }}</span></div>
                            @if ($isBingx)
                                <details class="mt-3 rounded-xl bg-cyan-300/[.05] p-3 text-xs text-[#b8afc2]"><summary class="cursor-pointer text-cyan-200">Инструкция проверки BingX</summary><ol class="mt-2 list-decimal space-y-1 pl-4"><li>Откройте BingX и перейдите в активы / историю пополнений.</li><li>Выберите валюту и сеть заявки.</li><li>Найдите транзакцию по TXID или сумме.</li><li>Сверьте адрес, полный TXID, статус и полученную сумму.</li><li>Вернитесь в CEO Money и отметьте проверки.</li></ol></details>
                            @endif
                            <div class="mt-3 space-y-2">
                                @foreach (\App\Services\DepositVerificationService::KEYS as $checkKey)
                                    @php
                                        $check = $verificationSummary['checks']->get($checkKey);
                                        $system = in_array($checkKey, \App\Services\DepositVerificationService::SYSTEM_KEYS, true);
                                        $snapshotValue = $check?->value_snapshot ? collect($check->value_snapshot)->filter(fn ($value) => $value !== null && $value !== '')->first() : null;
                                    @endphp
                                    <div class="rounded-xl border border-white/[.05] bg-white/[.025] p-3">
                                        <div class="flex items-start justify-between gap-3"><div class="min-w-0"><p class="text-sm {{ $check?->status === 'passed' ? 'text-emerald-200' : ($check?->status === 'failed' ? 'text-rose-200' : 'text-[#c3bacb]') }}">{{ $check?->status === 'passed' ? '✓' : ($check?->status === 'failed' ? '!' : '○') }} {{ \App\Support\DepositVerificationPresentation::label($checkKey) }}</p>@if($snapshotValue !== null)<p class="mt-1 break-all text-xs text-[#9080ba]">{{ $snapshotValue }}</p>@endif @if($check?->checkedBy)<p class="mt-1 text-[10px] text-[#786c87]">Проверил: {{ $check->checkedBy->name }} · {{ $check->checked_at?->format('d.m.Y H:i') }}</p>@elseif($system)<p class="mt-1 text-[10px] text-[#786c87]">Системная проверка</p>@endif</div>
                                            @if (!$system && !$terminalVerification)
                                                <div class="flex shrink-0 gap-1">@if($check?->status !== 'passed')<button type="button" wire:click="passVerification('{{ $checkKey }}')" class="cursor-pointer rounded-lg bg-emerald-300/10 px-2 py-1 text-xs text-emerald-200">Пройдено</button>@endif @if($check?->status !== 'failed')<button type="button" wire:click="failVerification('{{ $checkKey }}')" class="cursor-pointer rounded-lg bg-rose-300/10 px-2 py-1 text-xs text-rose-200">Ошибка</button>@endif @if($check?->status !== 'pending')<button type="button" wire:click="resetVerification('{{ $checkKey }}')" class="cursor-pointer rounded-lg bg-white/[.05] px-2 py-1 text-xs text-[#aaa0b3]">Сбросить</button>@endif</div>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </section>
                    @endif

                    @if ($actionStep === 'submit-confirm')
                        <h3 class="font-semibold">{{ $selectedDeposit->status === 'payment_submitted' ? 'Подтверждение платежа' : 'Передача на проверку' }}</h3>
                        <p class="mt-2 rounded-xl bg-purple-300/[.06] p-3 text-sm text-[#c9bfd1]">{{ $selectedDeposit->status === 'payment_submitted' ? 'Подтвердите обнаруженный платёж и передайте заявку в штатную проверку поступления.' : 'Вы берёте заявку в обработку. После этого потребуется пройти проверку поступления.' }}</p>
                        <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                            @foreach ([['Заявлено', MoneyFormatter::format($selectedDeposit->requested_amount).' '.$selectedDeposit->currency], ['Фактически получено', MoneyFormatter::format($receivedAmount).' '.$selectedDeposit->currency], ['TXID', $txid ?: '—'], ['Комиссия', MoneyFormatter::format($preview['fee_amount'] ?? '0').' '.$selectedDeposit->currency], ['В инвестицию', MoneyFormatter::format($preview['net_amount'] ?? '0').' '.$selectedDeposit->currency]] as [$label, $value])
                                <div><dt class="text-xs text-[#9080ba]">{{ $label }}</dt><dd class="mt-1 break-all">{{ $value }}</dd></div>
                            @endforeach
                        </dl>
                        <div class="mt-5 flex flex-col-reverse justify-end gap-2 sm:flex-row"><button type="button" wire:click="$set('actionStep', 'details')" class="cursor-pointer rounded-xl px-4 py-2">Назад</button><button type="button" wire:click="submitDeposit" wire:loading.attr="disabled" class="cursor-pointer rounded-xl bg-gradient-to-r from-[#9d60eb] to-[#4dbdd5] px-4 py-2 font-semibold disabled:cursor-not-allowed disabled:opacity-50">{{ $selectedDeposit->status === 'payment_submitted' ? 'Подтвердить платёж' : 'Подтвердить передачу на проверку' }}</button></div>
                    @elseif ($actionStep === 'confirm')
                        <h3 class="font-semibold">Подтверждение пополнения</h3>
                        <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                            @foreach ([['Инвестор', $selectedDeposit->investor->user->name], ['Получено', MoneyFormatter::format($received).' '.$selectedDeposit->currency], ['Комиссия', MoneyFormatter::format($selectedDeposit->fee_amount).' '.$selectedDeposit->currency], ['В инвестицию', MoneyFormatter::format($selectedDeposit->net_investment_amount ?? '0').' '.$selectedDeposit->currency], ['Сеть', $selectedDeposit->network ?: '—'], ['Адрес', $selectedDeposit->deposit_address_snapshot ?: '—'], ['Provider', $selectedDeposit->provider_snapshot ?: '—'], ['TXID', $selectedDeposit->txid ?: '—']] as [$label, $value])
                                <div><dt class="text-xs text-[#9080ba]">{{ $label }}</dt><dd class="mt-1 break-all">{{ $value }}</dd></div>
                            @endforeach
                        </dl>
                        <p class="mt-4 rounded-xl border border-amber-300/15 bg-amber-300/[.05] p-3 text-sm text-amber-100">После подтверждения будет создана новая инвестиция и финансовая транзакция.</p>
                        <div class="mt-5 flex flex-col-reverse justify-end gap-2 sm:flex-row"><button type="button" wire:click="$set('actionStep', 'details')" class="cursor-pointer rounded-xl px-4 py-2">Назад</button><button type="button" wire:click="confirmDeposit" wire:loading.attr="disabled" class="cursor-pointer rounded-xl bg-gradient-to-r from-[#9d60eb] to-[#4dbdd5] px-4 py-2 font-semibold disabled:cursor-not-allowed disabled:opacity-50">Подтвердить пополнение</button></div>
                    @elseif (in_array($actionStep, ['reject', 'cancel'], true))
                        <h3 class="font-semibold">{{ $actionStep === 'reject' ? 'Отклонить пополнение' : 'Отменить заявку' }}</h3>
                        <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-3"><div><dt class="text-xs text-[#9080ba]">Инвестор</dt><dd class="mt-1">{{ $selectedDeposit->investor->user->name }}</dd></div><div><dt class="text-xs text-[#9080ba]">Сумма</dt><dd class="mt-1">{{ MoneyFormatter::format($selectedDeposit->requested_amount) }} {{ $selectedDeposit->currency }}</dd></div><div><dt class="text-xs text-[#9080ba]">TXID</dt><dd class="mt-1 break-all">{{ $selectedDeposit->txid ?: '—' }}</dd></div></dl>
                        @if($actionStep === 'cancel')<p class="mt-4 rounded-xl border border-amber-300/15 bg-amber-300/[.05] p-3 text-sm text-amber-100">Заявка будет закрыта без создания инвестиции.</p>@endif
                        <label class="mt-4 block text-sm text-[#aaa0b3]">Причина<textarea wire:model="decisionReason" rows="4" maxlength="2000" class="mt-2 w-full rounded-xl border border-white/[.09] bg-[#2c2539] p-3 text-white focus:border-cyan-300/40 focus:outline-none"></textarea></label>
                        <div class="mt-5 flex flex-col-reverse justify-end gap-2 sm:flex-row"><button type="button" wire:click="$set('actionStep', 'details')" class="cursor-pointer rounded-xl px-4 py-2">Назад</button><button type="button" wire:click="{{ $actionStep === 'reject' ? 'rejectDeposit' : 'cancelDeposit' }}" wire:loading.attr="disabled" class="cursor-pointer rounded-xl bg-rose-400/15 px-4 py-2 font-semibold text-rose-100 disabled:cursor-not-allowed disabled:opacity-50">{{ $actionStep === 'reject' ? 'Отклонить заявку' : 'Отменить заявку' }}</button></div>
                    @else
                        <dl class="grid gap-3 text-sm sm:grid-cols-2">
                            @foreach ([['Инвестор', $selectedDeposit->investor->user->name], ['Программа', $programName], ['Статус', InvestorPresentation::adminDepositStatus($selectedDeposit->status)], ['Дата заявки', $selectedDeposit->requested_at->format('d.m.Y H:i')], ['Заявленная сумма', MoneyFormatter::format($selectedDeposit->requested_amount).' '.$selectedDeposit->currency], ['Полученная сумма', $selectedDeposit->received_amount ? MoneyFormatter::format($selectedDeposit->received_amount).' '.$selectedDeposit->currency : '—'], ['Комиссия', MoneyFormatter::format($shownFee).' '.$selectedDeposit->currency], ['Сумма в инвестицию', $shownNet ? MoneyFormatter::format($shownNet).' '.$selectedDeposit->currency : '—'], ['Валюта', $selectedDeposit->currency], ['Сеть', $paymentNetwork ?: '—'], ['Адрес платежа', $paymentAddress ?: '—'], ['Memo/Tag', $paymentMemo ?: '—'], ['Provider', $selectedDeposit->provider_snapshot ?: '—'], ['TXID', $selectedDeposit->txid ?: '—'], ['Тип комиссии', FeeRulePresentation::economicType($selectedDeposit->fee_economic_type_snapshot)], ['Плательщик', FeeRulePresentation::payer($selectedDeposit->fee_payer ?: '')]] as [$label, $value])
                                <div><dt class="text-xs text-[#9080ba]">{{ $label }}</dt><dd class="mt-1 break-all">{{ $value }}</dd></div>
                            @endforeach
                        </dl>

                        @if (in_array($selectedDeposit->status, ['pending', 'payment_submitted'], true))
                            <div class="mt-5 border-t border-white/[.07] pt-5"><div class="grid gap-4 sm:grid-cols-2"><label class="text-sm text-[#aaa0b3]">Фактически получено<input wire:model.live.debounce.350ms="receivedAmount" inputmode="decimal" class="mt-2 w-full rounded-xl border border-white/[.09] bg-[#2c2539] px-3 py-2.5 text-white focus:border-cyan-300/40 focus:outline-none"></label><label class="text-sm text-[#aaa0b3]">TXID<input wire:model="txid" maxlength="255" class="mt-2 w-full rounded-xl border border-white/[.09] bg-[#2c2539] px-3 py-2.5 text-white focus:border-cyan-300/40 focus:outline-none"></label></div><div class="mt-4 flex flex-col gap-2 sm:flex-row"><button type="button" wire:click="requestSubmitConfirmation" class="cursor-pointer rounded-xl bg-gradient-to-r from-[#9d60eb] to-[#4dbdd5] px-5 py-2.5 font-semibold">{{ $selectedDeposit->status === 'payment_submitted' ? 'Подтвердить платёж' : 'Взять на проверку' }}</button><button type="button" wire:click="beginDecision('reject')" class="cursor-pointer rounded-xl bg-rose-300/10 px-4 py-2.5 text-rose-100">{{ $selectedDeposit->status === 'payment_submitted' ? 'Отклонить платёж' : 'Отклонить' }}</button><button type="button" wire:click="beginDecision('cancel')" class="cursor-pointer rounded-xl bg-white/[.05] px-4 py-2.5 text-[#ccc3d4]">Отменить</button></div></div>
                        @elseif ($selectedDeposit->status === 'submitted')
                            <div class="mt-5 flex flex-col gap-2 border-t border-white/[.07] pt-5 sm:flex-row"><button type="button" wire:click="requestConfirmation" @disabled(!($confirmationPreflight['can_confirm'] ?? false) || !($verificationSummary['complete'] ?? false)) class="cursor-pointer rounded-xl bg-gradient-to-r from-[#9d60eb] to-[#4dbdd5] px-5 py-2.5 font-semibold disabled:cursor-not-allowed disabled:opacity-40">Подтвердить пополнение</button><button type="button" wire:click="beginDecision('reject')" class="cursor-pointer rounded-xl bg-rose-300/10 px-4 py-2.5 text-rose-100">Отклонить</button><button type="button" wire:click="beginDecision('cancel')" class="cursor-pointer rounded-xl bg-white/[.05] px-4 py-2.5 text-[#ccc3d4]">Отменить</button></div>
                        @elseif (in_array($selectedDeposit->status, ['rejected', 'cancelled'], true))
                            <div class="mt-5 rounded-xl bg-white/[.03] p-3 text-sm text-[#aaa0b3]">Причина: {{ $selectedDeposit->status === 'rejected' ? $selectedDeposit->rejected_reason : $selectedDeposit->cancellation_reason }}</div>
                        @endif
                    @endif
                </div>
            </section>
        </div>
    @endif
</div>
