<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'CEO Money Admin' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen overflow-x-hidden bg-[#261e35] text-white">
    @php
        $nav = [
            ['Dashboard', 'admin.dashboard', '<path d="M4 4h6v6H4zM14 4h6v4h-6zM14 12h6v8h-6zM4 14h6v6H4z"/>'],
            ['Обращения', 'admin.inbox.index', '<path d="M21 15a4 4 0 0 1-4 4H8l-5 3v-4a4 4 0 0 1-2-3V7a4 4 0 0 1 4-4h12a4 4 0 0 1 4 4v8Z"/>'],
            ['Инвесторы', 'admin.investors.index', '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>'],
            ['Инвестиционные программы', 'admin.investment-programs.index', '<path d="M4 5h16v14H4zM8 9h8M8 13h5"/>'],
            ['Индивидуальные условия', 'admin.investor-investment-terms.index', '<path d="M4 6h16M4 12h16M4 18h10M8 3v6M16 9v6M10 15v6"/>'],
            ['Пополнения', 'admin.deposits.index', '<path d="M12 3v12M7 10l5 5 5-5M5 21h14"/>'],
            ['Выводы', 'admin.withdrawals.index', '<path d="M12 21V9M17 14l-5-5-5 5M5 3h14"/>'],
            ['Начисления', 'admin.accruals.index', '<path d="M3 17l5-5 4 4 8-9M16 7h4v4"/>'],
            ['Кошельки', 'admin.wallets.index', '<path d="M20 7V5a2 2 0 0 0-2-2H5a3 3 0 0 0 0 6h15v10a2 2 0 0 1-2 2H5a3 3 0 0 1-3-3V6M16 14h.01"/>'],
            ['Комиссии', 'admin.fees.index', '<circle cx="7" cy="7" r="2"/><circle cx="17" cy="17" r="2"/><path d="M6 18 18 6"/>'],
            ['Аудит', 'admin.audit.index', '<path d="M9 11l3 3L22 4M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>'],
            ['Администраторы', 'admin.administrators.index', '<path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2M8.5 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM19 8v6M16 11h6"/>'],
            ['Настройки', 'admin.settings.index', '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06-2.83 2.83-.06-.06a1.7 1.7 0 0 0-1.88-.34 1.7 1.7 0 0 0-1.03 1.56V21h-4v-.09A1.7 1.7 0 0 0 9 19.37a1.7 1.7 0 0 0-1.88.34l-.06.06-2.83-2.83.06-.06A1.7 1.7 0 0 0 4.63 15 1.7 1.7 0 0 0 3.09 14H3v-4h.09A1.7 1.7 0 0 0 4.63 9a1.7 1.7 0 0 0-.34-1.88l-.06-.06 2.83-2.83.06.06A1.7 1.7 0 0 0 9 4.63h.01A1.7 1.7 0 0 0 10 3.09V3h4v.09A1.7 1.7 0 0 0 15 4.63a1.7 1.7 0 0 0 1.88-.34l.06-.06 2.83 2.83-.06.06A1.7 1.7 0 0 0 19.37 9v.01A1.7 1.7 0 0 0 20.91 10H21v4h-.09A1.7 1.7 0 0 0 19.4 15Z"/>'],
        ];
        $section = match(true) {
            request()->routeIs('admin.investors.*') => 'Инвесторы',
            request()->routeIs('admin.investment-programs.*') => 'Инвестиционные программы',
            request()->routeIs('admin.investor-investment-terms.*') => 'Индивидуальные условия',
            request()->routeIs('admin.deposits.*') => 'Пополнения',
            request()->routeIs('admin.withdrawals.*') => 'Выводы',
            request()->routeIs('admin.accruals.*') => 'Начисления',
            request()->routeIs('admin.fees.*') => 'Комиссии',
            request()->routeIs('admin.wallets.*') => 'Кошельки',
            request()->routeIs('admin.audit.*') => 'Аудит',
            request()->routeIs('admin.inbox.*') => 'Обращения',
            request()->routeIs('admin.notifications.*') => 'Уведомления',
            request()->routeIs('admin.settings.*') => 'Настройки',
            request()->routeIs('admin.administrators.*') => 'Администраторы',
            default => 'Dashboard',
        };
    @endphp
    <input id="admin-menu" type="checkbox" class="peer sr-only">
    <label for="admin-menu" class="fixed inset-0 z-40 hidden bg-[#171121]/80 backdrop-blur-sm peer-checked:block lg:hidden"></label>
    <aside class="fixed inset-y-0 left-0 z-50 flex w-[268px] -translate-x-full flex-col border-r border-white/[.07] bg-[#211a2e]/95 px-4 py-5 shadow-2xl shadow-black/30 backdrop-blur-xl transition-transform peer-checked:translate-x-0 lg:w-[92px] lg:translate-x-0 lg:items-center lg:px-3">
        <div class="mb-7 flex w-full items-center gap-3 px-2 lg:justify-center lg:px-0">
            <div class="flex size-11 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-[#bb7ff5] to-[#7557ed] text-sm font-black shadow-lg shadow-purple-900/30">CM</div>
            <div class="lg:hidden"><p class="font-semibold">CEO Money</p><p class="text-[11px] text-[#9080ba]">Admin panel</p></div>
        </div>
        <nav class="flex w-full flex-1 flex-col gap-1.5">
            @foreach($nav as [$label, $routeName, $icon])
                @php $active = $routeName && request()->routeIs($routeName === 'admin.dashboard' ? 'admin.dashboard' : $routeName); @endphp
                <a href="{{ $routeName ? route($routeName) : '#' }}" class="group relative flex h-12 items-center gap-3 rounded-2xl border px-3 transition lg:w-14 lg:justify-center lg:px-0 {{ $active ? 'border-[#b773f0]/35 bg-[#3b2d52] text-[#d8adff] shadow-[inset_3px_0_0_#70d7e8]' : 'border-transparent text-[#9080ba] hover:border-white/5 hover:bg-white/[.04] hover:text-white' }}">
                    <svg viewBox="0 0 24 24" class="size-5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">{!! $icon !!}</svg>
                    <span class="text-sm lg:hidden">{{ $label }}</span>
                    @if($routeName==='admin.inbox.index')@php $inboxBadge=\App\Models\SupportTicket::whereIn('status',['new','open'])->count()+\App\Models\WithdrawalRequest::whereIn('status',['new','review'])->count()+\App\Models\DepositRequest::whereIn('status',['pending','payment_submitted','submitted'])->count()+\App\Models\InvestorWallet::where('status','pending')->count(); @endphp @if($inboxBadge)<span class="absolute right-1 top-1 rounded-full bg-rose-400 px-1.5 text-[9px] text-white">{{ $inboxBadge }}</span>@endif @endif
                    <span class="pointer-events-none absolute left-[68px] z-50 hidden whitespace-nowrap rounded-xl border border-white/10 bg-[#342a44] px-3 py-2 text-xs text-white opacity-0 shadow-xl transition group-hover:opacity-100 lg:block">{{ $label }}</span>
                </a>
            @endforeach
        </nav>
        <form method="POST" action="{{ route('logout') }}" class="w-full">@csrf
            <button class="group relative flex h-12 w-full items-center gap-3 rounded-2xl px-3 text-[#9080ba] transition hover:bg-rose-400/10 hover:text-rose-300 lg:w-14 lg:justify-center lg:px-0">
                <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M10 17l5-5-5-5M15 12H3M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/></svg><span class="text-sm lg:hidden">Выйти</span><span class="pointer-events-none absolute left-[68px] hidden rounded-xl border border-white/10 bg-[#342a44] px-3 py-2 text-xs text-white opacity-0 group-hover:opacity-100 lg:block">Выйти</span>
            </button>
        </form>
    </aside>
    <div class="min-h-screen lg:pl-[92px]">
        <header class="sticky top-0 z-30 flex h-[74px] items-center justify-between border-b border-white/[.06] bg-[#261e35]/90 px-4 backdrop-blur-xl sm:px-6 xl:px-8">
            <div class="flex items-center gap-3"><label for="admin-menu" class="flex size-10 cursor-pointer items-center justify-center rounded-xl border border-white/10 bg-white/[.04] text-[#c8b9df] lg:hidden"><svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M4 12h16M4 18h16"/></svg></label><div><p class="text-[11px] uppercase tracking-[.18em] text-[#7d6e9d]">CEO Money</p><h1 class="text-xl font-semibold tracking-tight">{{ $section }}</h1></div></div>
            <div class="flex items-center gap-2 sm:gap-3">
                <div class="hidden h-10 w-56 items-center gap-2 rounded-2xl border border-white/[.07] bg-[#31283f] px-3 text-[#7f7298] md:flex"><svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg><span class="text-xs">Поиск…</span><span class="ml-auto rounded-md bg-white/5 px-1.5 py-0.5 text-[10px]">⌘K</span></div>
                @livewire('admin.notifications.dropdown')
                <div class="flex items-center gap-2 rounded-2xl border border-white/[.07] bg-[#31283f] p-1.5 pr-2 sm:pr-3"><div class="flex size-8 items-center justify-center rounded-xl bg-gradient-to-br from-[#b66ff0] to-[#4d98dc] text-xs font-semibold">{{ mb_strtoupper(mb_substr(auth()->user()->name,0,1)) }}</div><div class="hidden sm:block"><p class="max-w-32 truncate text-xs font-medium">{{ auth()->user()->name }}</p><p class="text-[10px] text-[#8e80a8]">Administrator</p></div></div>
            </div>
        </header>
        <main class="w-full px-4 py-5 sm:px-6 sm:py-6 xl:px-8">{{ $slot }}</main>
    </div>
    @livewireScripts
</body>
</html>
