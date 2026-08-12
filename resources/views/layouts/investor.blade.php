<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'CEO Money' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js']) @livewireStyles
</head>
<body class="min-h-screen overflow-x-hidden bg-[#261e35] text-white">
@php
    $nav = [
        ['Обзор','dashboard','M3 12h7V3H3v9Zm11 9h7v-9h-7v9ZM3 21h7v-5H3v5Zm11-13h7V3h-7v5Z'],
        ['Начисления','investor.accruals','m3 17 5-5 4 4 8-9m-4 0h4v4'],
        ['Финансы','investor.finance','M4 19V9m5 10V5m6 14v-7m5 7V3'],
        ['Программы','investor.programs','M4 5h16v14H4zm4 4h8m-8 4h5'],
        ['Выводы','investor.withdrawals','M12 21V9m5 5-5-5-5 5M5 3h14'],
        ['Кошельки','investor.wallets','M20 7V5a2 2 0 0 0-2-2H5a3 3 0 0 0 0 6h15v10a2 2 0 0 1-2 2H5a3 3 0 0 1-3-3V6m14 8h.01'],
        ['Поддержка','investor.support.index','M21 15a4 4 0 0 1-4 4H8l-5 3v-4a4 4 0 0 1-2-3V7a4 4 0 0 1 4-4h12a4 4 0 0 1 4 4v8Z'],
    ];
    $section = request()->routeIs('investor.profile') ? 'Профиль' : (collect($nav)->first(fn($item) => request()->routeIs($item[1]) || ($item[1]==='investor.support.index' && request()->routeIs('investor.support.*')))[0] ?? 'Личный кабинет');
@endphp
<input id="investor-menu" type="checkbox" class="peer sr-only">
<label for="investor-menu" class="fixed inset-0 z-40 hidden bg-[#171121]/80 backdrop-blur-sm peer-checked:block lg:hidden"></label>
<aside class="fixed inset-y-0 left-0 z-50 flex w-[268px] -translate-x-full flex-col border-r border-white/[.07] bg-[#211a2e]/95 px-4 py-5 shadow-2xl backdrop-blur-xl transition peer-checked:translate-x-0 lg:w-[92px] lg:translate-x-0 lg:items-center lg:px-3">
    <div class="mb-8 flex w-full items-center gap-3 px-2 lg:justify-center lg:px-0"><div class="flex size-11 items-center justify-center rounded-2xl bg-gradient-to-br from-[#bb7ff5] to-[#58c8df] text-sm font-black">CM</div><div class="lg:hidden"><p class="font-semibold">CEO Money</p><p class="text-xs text-[#9080ba]">Investor</p></div></div>
    <nav class="flex w-full flex-1 flex-col gap-2">
        @foreach($nav as [$label,$routeName,$path])
            <a href="{{ route($routeName) }}" class="group relative flex h-12 items-center gap-3 rounded-2xl border px-3 transition lg:w-14 lg:justify-center lg:px-0 {{ request()->routeIs($routeName) ? 'border-[#b773f0]/35 bg-[#3b2d52] text-[#79d7e8] shadow-[inset_3px_0_0_#b773f0]' : 'border-transparent text-[#9080ba] hover:bg-white/[.04] hover:text-white' }}"><svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="1.7"><path d="{{ $path }}"/></svg><span class="text-sm lg:hidden">{{ $label }}</span><span class="pointer-events-none absolute left-[68px] hidden whitespace-nowrap rounded-xl border border-white/10 bg-[#342a44] px-3 py-2 text-xs opacity-0 shadow-xl group-hover:opacity-100 lg:block">{{ $label }}</span></a>
        @endforeach
    </nav>
    <form method="POST" action="{{ route('logout') }}" class="w-full">@csrf<button class="flex h-12 w-full items-center gap-3 rounded-2xl px-3 text-[#9080ba] hover:bg-rose-400/10 hover:text-rose-300 lg:w-14 lg:justify-center lg:px-0"><svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor"><path d="m10 17 5-5-5-5m5 5H3m12-9h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/></svg><span class="lg:hidden">Выйти</span></button></form>
</aside>
<div class="min-h-screen lg:pl-[92px]">
    <header class="sticky top-0 z-30 flex h-[74px] items-center justify-between border-b border-white/[.06] bg-[#261e35]/90 px-4 backdrop-blur-xl sm:px-6 xl:px-8">
        <div class="flex items-center gap-3"><label for="investor-menu" class="flex size-10 cursor-pointer items-center justify-center rounded-xl border border-white/10 bg-white/[.04] lg:hidden"><svg viewBox="0 0 24 24" class="size-5" stroke="currentColor"><path d="M4 6h16M4 12h16M4 18h16"/></svg></label><div><p class="text-[11px] uppercase tracking-[.18em] text-[#7d6e9d]">CEO Money</p><h1 class="text-xl font-semibold">{{ $section }}</h1></div></div>
        <div class="flex items-center gap-2"><button aria-label="Уведомления" class="relative flex size-10 items-center justify-center rounded-2xl border border-white/[.07] bg-[#31283f]"><svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9m-11 4h4"/></svg><span class="absolute right-2.5 top-2.5 size-1.5 rounded-full bg-cyan-300"></span></button>
            <div x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape.window="open = false" class="relative">
                <button type="button" x-on:click="open = ! open" x-bind:aria-expanded="open.toString()" aria-haspopup="menu" class="flex cursor-pointer items-center gap-2 rounded-2xl border border-white/[.07] bg-[#31283f] p-1.5 pr-3 transition hover:border-purple-300/20 hover:bg-[#372d46] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-cyan-300/60"><span class="flex size-8 items-center justify-center rounded-xl bg-gradient-to-br from-[#b66ff0] to-[#4d98dc] text-xs font-semibold">{{ mb_strtoupper(mb_substr(auth()->user()->name,0,1)) }}</span><span class="hidden max-w-40 truncate text-xs sm:block">{{ auth()->user()->name }}</span><svg viewBox="0 0 24 24" class="hidden size-3.5 text-[#8f819e] transition-transform sm:block" x-bind:class="open && 'rotate-180'" fill="none" stroke="currentColor"><path d="m7 10 5 5 5-5"/></svg></button>
                <div x-cloak x-show="open" x-transition.origin.top.right role="menu" class="absolute right-0 top-[calc(100%+8px)] z-50 w-48 overflow-hidden rounded-2xl border border-white/[.09] bg-[#342c43] p-1.5 shadow-2xl">
                    <a role="menuitem" href="{{ route('investor.profile') }}" class="flex items-center gap-2 rounded-xl px-3 py-2.5 text-sm text-[#ddd5e5] hover:bg-white/[.05] hover:text-white">Профиль</a>
                    <a role="menuitem" href="{{ route('investor.profile') }}#security" class="flex items-center gap-2 rounded-xl px-3 py-2.5 text-sm text-[#ddd5e5] hover:bg-white/[.05] hover:text-white">Безопасность</a>
                    <form method="POST" action="{{ route('logout') }}" class="border-t border-white/[.06] pt-1.5">@csrf<button type="submit" role="menuitem" class="w-full cursor-pointer rounded-xl px-3 py-2.5 text-left text-sm text-rose-300 hover:bg-rose-400/[.08]">Выйти</button></form>
                </div>
            </div>
        </div>
    </header>
    <main class="w-full px-4 py-5 sm:px-6 xl:px-8">{{ $slot }}</main>
</div>
@livewireScripts
</body></html>
