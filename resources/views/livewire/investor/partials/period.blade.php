<div class="rounded-2xl border border-white/[.07] bg-[#332b40] p-2">
    <div class="flex flex-wrap gap-2">
        @foreach(['today'=>'Сегодня','month'=>'Этот месяц','all'=>'Всё время','custom'=>'Период'] as $mode=>$label)
            <button type="button" wire:click="selectPeriod('{{ $mode }}')" class="rounded-xl px-3 py-2 text-xs transition {{ $periodMode === $mode ? 'bg-gradient-to-r from-[#8a5ee8] to-[#4ebdd5] text-white' : 'text-[#a99abc] hover:bg-white/5' }}">{{ $label }}</button>
        @endforeach
    </div>
    @if($periodMode === 'custom')
        <div class="mt-3 grid gap-3 sm:grid-cols-2 {{ isset($dashboardPeriod) ? 'xl:grid-cols-[1fr_1fr_auto_auto] xl:items-end' : '' }}">
            <label class="text-xs text-[#a99abc]">Дата от<input type="date" wire:model="periodFrom" max="{{ now()->toDateString() }}" class="mt-1 w-full rounded-xl border border-white/10 bg-[#261e35] px-3 py-2 text-white"></label>
            <label class="text-xs text-[#a99abc]">Дата до<input type="date" wire:model="periodTo" max="{{ now()->toDateString() }}" class="mt-1 w-full rounded-xl border border-white/10 bg-[#261e35] px-3 py-2 text-white"></label>
            @if(isset($dashboardPeriod))
                <button type="button" wire:click="applyPeriod" class="h-[38px] rounded-xl bg-gradient-to-r from-[#8a5ee8] to-[#4ebdd5] px-4 text-xs font-medium">Применить</button>
                <button type="button" wire:click="resetPeriod" class="h-[38px] rounded-xl border border-white/10 px-4 text-xs text-[#b4a8c1]">Сбросить</button>
            @endif
        </div>
        @error('periodFrom')<p class="mt-2 text-xs text-rose-300">{{ $message }}</p>@enderror
        @error('periodTo')<p class="mt-2 text-xs text-rose-300">{{ $message }}</p>@enderror
    @endif
</div>
