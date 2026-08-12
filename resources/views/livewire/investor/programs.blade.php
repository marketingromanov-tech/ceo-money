<div class="mx-auto max-w-[1500px]">
    <div><h2 class="text-2xl font-semibold">Доступные программы</h2><p class="mt-1 text-xs text-[#9080ba]">Выберите программу, затем укажите сумму новой инвестиции</p></div>
    @error('program')<p class="mt-4 rounded-xl bg-rose-300/10 p-3 text-sm text-rose-200">{{ $message }}</p>@enderror
    <div class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach($programs as $program)
            @php $version=$program->versions->first();$category=['start'=>'Начальная программа','standard'=>'Популярная программа','advanced'=>'Оптимальный баланс','premium'=>'Для крупного капитала','vip'=>'Индивидуальные условия'][$program->slug]??$program->description; @endphp
            <article data-program="{{ $program->slug }}" class="flex flex-col rounded-[18px] border border-white/[.07] bg-[#3c354a] p-5 transition hover:-translate-y-0.5 hover:border-purple-300/25">
                <div><h3 class="text-lg font-semibold">{{ $program->name }}</h3><p class="mt-1 text-xs text-purple-200">{{ $category }}</p><p class="mt-2 text-xs leading-relaxed text-[#887a97]">{{ $program->description }}</p></div>
                <div class="mt-6"><p class="text-3xl font-semibold text-purple-200">{{ \App\Support\InvestmentTermPresentation::rate((string)$version->monthly_rate) }}</p><p class="text-xs text-[#9080ba]">в месяц</p></div>
                <div class="mt-5 grid grid-cols-2 gap-3 rounded-2xl bg-white/[.025] p-3 text-sm">@if($program->max_amount)<div><p class="text-[10px] text-[#8e819d]">От</p><b>{{ \App\Support\MoneyFormatter::format($program->min_amount,0) }} {{ $program->currency }}</b></div><div><p class="text-[10px] text-[#8e819d]">До</p><b>{{ \App\Support\MoneyFormatter::format($program->max_amount,0) }} {{ $program->currency }}</b></div>@else<div class="col-span-2"><p class="text-[10px] text-[#8e819d]">Диапазон</p><b>От {{ \App\Support\MoneyFormatter::format($program->min_amount,0) }} {{ $program->currency }}</b></div>@endif</div>
                <div class="mt-4 space-y-3 text-sm"><div class="flex justify-between gap-3"><span class="text-[#9080ba]">Капитал доступен</span><b>через {{ $version->lock_months }} месяцев</b></div><div class="flex justify-between gap-3"><span class="text-[#9080ba]">Частичный вывод</span><b>{{ $program->is_partial_withdrawal_allowed?'Да':'Нет' }}</b></div></div>
                <button type="button" wire:click="selectProgram({{ $program->id }})" wire:loading.attr="disabled" class="mt-6 w-full cursor-pointer rounded-xl bg-gradient-to-r from-[#ac6aec] to-[#6875e9] px-4 py-3 text-sm font-semibold text-white transition hover:brightness-110 disabled:cursor-not-allowed disabled:opacity-60">Выбрать {{ $program->name }}</button>
            </article>
        @endforeach
    </div>
</div>
