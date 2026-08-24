<article class="overflow-hidden rounded-[20px] border border-white/[.07] bg-[#3c354a]">
    <header class="flex flex-col gap-3 border-b border-white/[.06] bg-[#332c42] px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
        <div><h3 class="text-sm font-semibold">Индивидуальные инвестиционные условия</h3><p class="mt-1 text-[10px] text-[#9080ba]">Версии условий сохраняются в истории и применяются по датам действия</p></div>
        <button type="button" wire:click="openIndividualTermForm" class="rounded-xl bg-gradient-to-r from-[#ac6aec] to-[#7a72e8] px-4 py-2.5 text-xs font-semibold">+ Добавить индивидуальные условия</button>
    </header>
    <div class="divide-y divide-white/[.05]">
        @forelse($individualTerms as $term)
            @php($currentVersion=$term->versions->first(fn($version)=>$version->valid_from->lte(today())&&($version->valid_to===null||$version->valid_to->gte(today())))??$term->versions->first())
            <section class="p-5">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div class="flex flex-wrap items-center gap-2"><span class="rounded-full px-2.5 py-1 text-[10px] {{ $term->status==='active'?'bg-emerald-300/10 text-emerald-200':'bg-white/[.06] text-[#9f92ae]' }}">{{ $term->status==='active'?'Активно':'Неактивно' }}</span><b>{{ $currentVersion->currency }}</b><span class="text-[10px] text-[#9080ba]">{{ $term->versions->count() }} версий</span></div>
                    <div class="flex gap-2"><button type="button" wire:click="openIndividualTermForm({{ $term->id }})" class="rounded-xl border border-cyan-300/20 px-3 py-2 text-xs text-cyan-100">Создать новую версию</button>@if($term->status==='active')<button type="button" wire:click="deactivateIndividualTerm({{ $term->id }})" class="rounded-xl border border-rose-300/20 px-3 py-2 text-xs text-rose-200">Деактивировать</button>@endif</div>
                </div>
                <h4 class="mt-5 text-xs font-semibold text-white">История версий</h4>
                <div class="mt-3 space-y-3">
                    @foreach($term->versions as $version)
                        <details class="rounded-xl border border-white/[.06] bg-white/[.025] p-4" @if($loop->first) open @endif>
                            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 text-xs"><span class="font-semibold">Версия от {{ $version->valid_from->format('d.m.Y') }}</span><span class="text-[#9080ba]">до {{ $version->valid_to?->format('d.m.Y')??'бессрочно' }}</span></summary>
                            @if(!$loop->last)<p class="mt-3 text-[10px] text-cyan-200">Изменения относительно предыдущей версии</p>@else<p class="mt-3 text-[10px] text-[#9080ba]">Начальная версия</p>@endif
                            <dl class="mt-4 grid gap-3 text-xs sm:grid-cols-2 xl:grid-cols-4">
                                @foreach([['Ставка в месяц',\App\Support\InvestmentTermPresentation::rate((string)$version->monthly_rate)],['Срок инвестирования',$version->term_months.' мес.'],['Минимальная сумма',$version->min_amount!==null?\App\Support\MoneyFormatter::format($version->min_amount).' '.$version->currency:'—'],['Максимальная сумма',$version->max_amount!==null?\App\Support\MoneyFormatter::format($version->max_amount).' '.$version->currency:'—'],['Блокировка капитала',$version->lock_days!==null?$version->lock_days.' дн.':'—'],['Частичный вывод',$version->partial_withdrawal?'Разрешён':'Запрещён'],['Дата начала',$version->valid_from->format('d.m.Y')],['Дата окончания',$version->valid_to?->format('d.m.Y')??'Бессрочно']] as [$label,$value])
                                    <div><dt class="text-[#9080ba]">{{ $label }}</dt><dd class="mt-1 font-medium text-white">{{ $value }}</dd></div>
                                @endforeach
                            </dl>
                            <div class="mt-4 text-xs"><p class="text-[#9080ba]">Комментарий администратора</p><p class="mt-1">{{ $version->notes?:'—' }}</p><p class="mt-3 text-[10px] text-[#81748f]">Создал: {{ $version->createdByAdmin?->name??'Система' }} · {{ $version->created_at->format('d.m.Y H:i') }}</p></div>
                        </details>
                    @endforeach
                </div>
            </section>
        @empty
            <div class="p-8 text-center text-xs text-[#9080ba]">Индивидуальные условия ещё не назначены.</div>
        @endforelse
    </div>
</article>

@if($showIndividualTermForm)
    <div class="fixed inset-0 z-50 flex items-center justify-center p-4" role="dialog" aria-modal="true"><button type="button" wire:click="closeIndividualTermForm" class="absolute inset-0 bg-[#171020]/80 backdrop-blur-sm"></button>
        <form wire:submit="saveIndividualTerm" class="relative z-10 max-h-[90vh] w-full max-w-3xl overflow-y-auto rounded-[24px] border border-white/10 bg-[#3c354a]">
            <header class="flex items-center justify-between border-b border-white/[.07] px-6 py-4"><div><h3 class="font-semibold">{{ $editingIndividualTermId?'Создать новую версию':'Создать' }} индивидуальные условия</h3>@if($editingIndividualTermId)<p class="mt-1 text-[10px] text-amber-200">Предыдущая версия будет закрыта днём перед новой датой начала.</p>@endif</div><button type="button" wire:click="closeIndividualTermForm" class="text-[#a89ab5]">✕</button></header>
            <div class="grid gap-4 p-6 sm:grid-cols-2">
                @foreach([['individualCurrency','Валюта','text'],['individualMinAmount','Минимальная сумма','text'],['individualMaxAmount','Максимальная сумма','text'],['individualMonthlyRate','Доходность в месяц (%)','text'],['individualTermMonths','Срок (месяцев)','number'],['individualLockDays','Блокировка капитала (дней)','number'],['individualStartsAt',$editingIndividualTermId?'Новая версия действует с':'Дата начала','date'],['individualEndsAt','Дата окончания','date']] as [$model,$label,$type])<label class="text-xs text-[#a99db6]">{{ $label }}<input wire:model="{{ $model }}" type="{{ $type }}" class="mt-2 h-12 w-full rounded-xl border border-white/10 bg-[#2f293e] px-3 text-white">@error($model)<span class="mt-1 block text-[10px] text-rose-200">{{ $message }}</span>@enderror</label>@endforeach
                <label class="text-xs text-[#a99db6]">Статус<select wire:model="individualStatus" class="mt-2 h-12 w-full rounded-xl border border-white/10 bg-[#2f293e] px-3 text-white"><option value="active">Активно</option><option value="inactive">Неактивно</option></select></label>
                <label class="flex items-center gap-3 text-xs"><input wire:model="individualPartialWithdrawal" type="checkbox" class="rounded"> Разрешить частичный вывод</label>
                <label class="sm:col-span-2 text-xs text-[#a99db6]">Комментарий<textarea wire:model="individualNotes" rows="3" class="mt-2 w-full rounded-xl border border-white/10 bg-[#2f293e] px-3 py-3 text-white"></textarea></label>
            </div>
            <footer class="flex justify-end gap-3 border-t border-white/[.07] px-6 py-4"><button type="button" wire:click="closeIndividualTermForm" class="rounded-xl border border-white/10 px-4 py-2.5 text-xs">Отмена</button><button class="rounded-xl bg-gradient-to-r from-[#ac6aec] to-[#727ce7] px-5 py-2.5 text-xs font-semibold">{{ $editingIndividualTermId?'Создать версию':'Сохранить' }}</button></footer>
        </form>
    </div>
@endif
