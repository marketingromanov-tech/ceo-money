<div x-data="{ open: false }" x-on:click.outside="open = false" class="relative">
    <button type="button" x-on:click="open = !open" aria-label="Уведомления" x-bind:aria-expanded="open.toString()" class="relative flex size-10 cursor-pointer items-center justify-center rounded-2xl border border-white/[.07] bg-[#31283f] text-[#a99abc] hover:text-white">
        <svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg>
        @if($unreadCount)<span class="absolute -right-1 -top-1 min-w-5 rounded-full bg-rose-400 px-1.5 py-0.5 text-center text-[9px] font-semibold text-white">{{ $unreadCount > 99 ? '99+' : $unreadCount }}</span>@endif
    </button>
    <div x-cloak x-show="open" x-transition.origin.top.right class="absolute right-0 top-12 z-50 w-[min(380px,calc(100vw-2rem))] overflow-hidden rounded-[18px] border border-white/10 bg-[#342c43] shadow-2xl shadow-black/40">
        <div class="flex items-center justify-between border-b border-white/[.06] px-4 py-3"><div><h2 class="text-sm font-semibold">Уведомления</h2><p class="mt-0.5 text-[10px] text-[#8f82a0]">{{ $unreadCount ? 'Непрочитанных: '.$unreadCount : 'Новых уведомлений нет' }}</p></div>@if($unreadCount)<button type="button" wire:click="markAllRead" class="cursor-pointer text-[10px] text-cyan-200 hover:text-white">Отметить все как прочитанные</button>@endif</div>
        <div class="max-h-[420px] overflow-y-auto divide-y divide-white/[.05]">
            @forelse($notifications as $notification)
                @php $data=$notification->data; $meta=collect([$data['investor_name'] ?: ($data['investor_code'] ?? null), isset($data['amount']) ? \App\Support\MoneyFormatter::format((string)$data['amount']).' '.($data['currency'] ?? '') : null, isset($data['network']) ? ($data['currency'] ?? '').' / '.$data['network'] : null])->filter()->implode(' · '); @endphp
                <button type="button" wire:click="open('{{ $notification->id }}')" class="flex w-full cursor-pointer gap-3 px-4 py-3 text-left hover:bg-white/[.035] {{ $notification->read_at ? '' : 'bg-purple-300/[.035]' }}"><span class="mt-1 size-2 shrink-0 rounded-full {{ $notification->read_at ? 'bg-white/10' : 'bg-cyan-300 shadow-[0_0_10px_#67e8f9]' }}"></span><span class="min-w-0 flex-1"><b class="block text-xs font-medium text-white">{{ $data['title'] }}</b>@if($meta)<span class="mt-1 block truncate text-[11px] text-[#a99bad]">{{ $meta }}</span>@endif<span class="mt-1 block text-[9px] text-[#776b85]">{{ $notification->created_at->diffForHumans() }}</span></span></button>
            @empty
                <div class="px-5 py-10 text-center text-xs text-[#81748f]">Уведомлений пока нет</div>
            @endforelse
        </div>
        <a href="{{ route('admin.notifications.index') }}" wire:navigate class="block border-t border-white/[.06] px-4 py-3 text-center text-xs text-[#c08cee] hover:text-white">Все уведомления</a>
    </div>
</div>
