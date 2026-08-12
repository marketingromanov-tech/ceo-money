<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Вход — CEO Money</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen overflow-x-hidden bg-[#21192e] text-white">
    <main class="relative isolate flex min-h-screen flex-col overflow-hidden">
        <div aria-hidden="true" class="pointer-events-none absolute inset-0 -z-20 bg-[radial-gradient(circle_at_16%_18%,rgba(172,106,236,.19),transparent_34%),radial-gradient(circle_at_85%_78%,rgba(76,190,215,.12),transparent_31%),linear-gradient(135deg,#21192e_0%,#281f38_48%,#1d1728_100%)]"></div>
        <div aria-hidden="true" class="pointer-events-none absolute -left-32 top-1/2 -z-10 size-80 -translate-y-1/2 rounded-full border border-purple-300/[.07]"></div>
        <div aria-hidden="true" class="pointer-events-none absolute -right-24 -top-24 -z-10 size-72 rounded-full border border-cyan-200/[.06]"></div>

        <div class="mx-auto grid w-full max-w-[1320px] flex-1 items-center gap-10 px-4 py-8 sm:px-7 lg:grid-cols-[minmax(0,.92fr)_minmax(420px,.68fr)] lg:gap-16 lg:px-10 lg:py-12 xl:gap-24">
            <section class="mx-auto w-full max-w-xl lg:mx-0">
                <div class="flex items-center gap-4">
                    <div class="flex size-14 shrink-0 items-center justify-center rounded-[19px] border border-white/10 bg-gradient-to-br from-[#bb7ff5] via-[#9a68e9] to-[#55bfd8] text-lg font-black shadow-[0_18px_50px_rgba(139,82,218,.24)]">CM</div>
                    <div>
                        <p class="text-2xl font-semibold tracking-tight sm:text-3xl">CEO Money</p>
                        <p class="mt-0.5 text-xs uppercase tracking-[.18em] text-[#9080ba]">Financial platform</p>
                    </div>
                </div>

                <div class="mt-7 sm:mt-10">
                    <h1 class="max-w-lg text-3xl font-semibold leading-tight tracking-[-.025em] sm:text-4xl xl:text-[46px]">Инвестиции под вашим контролем</h1>
                    <p class="mt-4 max-w-lg text-sm leading-6 text-[#aca0bb] sm:text-base sm:leading-7">Капитал, начисления, выплаты и история операций — в одном кабинете.</p>
                </div>

                <div class="mt-8 hidden grid-cols-2 gap-3 sm:grid lg:grid-cols-1 xl:grid-cols-2">
                    @foreach([
                        ['Ежедневные начисления', '<path d="M12 3v18M7 7.5A4 4 0 0 1 11 4h2a4 4 0 0 1 0 8h-2a4 4 0 0 0 0 8h2a4 4 0 0 0 4-3.5"/>'],
                        ['Прозрачная статистика', '<path d="M4 19V9m5 10V5m6 14v-7m5 7V3"/>'],
                        ['Управление капиталом', '<path d="M4 7h16M4 12h16M4 17h10M7 4v6m10 0v5"/>'],
                        ['Защищённые операции', '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-5"/>'],
                    ] as [$label, $icon])
                        <div class="flex items-center gap-3 rounded-2xl border border-white/[.065] bg-white/[.025] px-4 py-3.5 text-sm text-[#c7bdd2] backdrop-blur-sm">
                            <span class="flex size-9 shrink-0 items-center justify-center rounded-xl border border-cyan-200/10 bg-cyan-200/[.055] text-[#74d0e1]"><svg viewBox="0 0 24 24" class="size-[18px]" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">{!! $icon !!}</svg></span>
                            {{ $label }}
                        </div>
                    @endforeach
                </div>
            </section>

            <section class="mx-auto w-full max-w-[470px] lg:mx-0 lg:justify-self-end">
                <form method="POST" action="{{ route('login.store') }}" class="rounded-[24px] border border-white/[.1] bg-[#332b42]/90 p-5 shadow-[0_30px_90px_rgba(8,5,14,.38),0_0_70px_rgba(147,88,224,.08)] backdrop-blur-xl sm:p-9 xl:p-10">
                    @csrf
                    <div>
                        <h2 class="text-2xl font-semibold tracking-tight sm:text-[28px]">Добро пожаловать</h2>
                        <p class="mt-2 text-sm text-[#9f93af]">Войдите в свой аккаунт CEO Money</p>
                    </div>

                    @if($errors->any())
                        <div role="alert" class="mt-6 flex gap-3 rounded-2xl border border-rose-300/15 bg-rose-400/[.07] px-4 py-3.5 text-sm text-rose-200">
                            <svg viewBox="0 0 24 24" class="mt-0.5 size-4 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M12 8v5m0 3h.01"/></svg>
                            <div><p class="font-medium">Не удалось войти</p><p class="mt-0.5 text-xs text-rose-200/75">Проверьте введённые данные и попробуйте ещё раз.</p></div>
                        </div>
                    @endif

                    <div class="mt-7 space-y-5">
                        <label class="block">
                            <span class="mb-2 block text-sm font-medium text-[#d5cedd]">Email</span>
                            <span class="relative block">
                                <svg viewBox="0 0 24 24" class="pointer-events-none absolute left-4 top-1/2 size-[18px] -translate-y-1/2 text-[#857899]" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="3" y="5" width="18" height="14" rx="3"/><path d="m4 7 8 6 8-6"/></svg>
                                <input name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="email" class="h-[52px] w-full rounded-[15px] border bg-[#272132] pl-11 pr-4 text-sm text-white placeholder:text-[#6f647e] transition focus:border-[#a86be7] focus:ring-4 focus:ring-purple-400/[.08] {{ $errors->has('email') ? 'border-rose-300/40' : 'border-white/[.09]' }}" placeholder="name@example.com">
                            </span>
                            @error('email')<span class="mt-2 block text-xs text-rose-300">{{ $message }}</span>@enderror
                        </label>

                        <label class="block">
                            <span class="mb-2 block text-sm font-medium text-[#d5cedd]">Пароль</span>
                            <span class="relative block">
                                <svg viewBox="0 0 24 24" class="pointer-events-none absolute left-4 top-1/2 size-[18px] -translate-y-1/2 text-[#857899]" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="4" y="10" width="16" height="11" rx="3"/><path d="M8 10V7a4 4 0 0 1 8 0v3m-4 4v3"/></svg>
                                <input id="login-password" name="password" type="password" required autocomplete="current-password" class="h-[52px] w-full rounded-[15px] border bg-[#272132] pl-11 pr-12 text-sm text-white transition focus:border-[#66cadd] focus:ring-4 focus:ring-cyan-300/[.07] {{ $errors->has('password') ? 'border-rose-300/40' : 'border-white/[.09]' }}">
                                <button type="button" aria-label="Показать пароль" aria-pressed="false" onclick="const input=document.getElementById('login-password'); const visible=input.type==='text'; input.type=visible?'password':'text'; this.setAttribute('aria-pressed',String(!visible)); this.setAttribute('aria-label',visible?'Показать пароль':'Скрыть пароль'); this.querySelector('[data-eye]').classList.toggle('hidden',!visible); this.querySelector('[data-eye-off]').classList.toggle('hidden',visible)" class="absolute right-2.5 top-1/2 flex size-9 -translate-y-1/2 items-center justify-center rounded-xl text-[#897b9d] transition hover:bg-white/5 hover:text-white">
                                    <svg data-eye viewBox="0 0 24 24" class="size-[18px]" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/><circle cx="12" cy="12" r="2.5"/></svg>
                                    <svg data-eye-off viewBox="0 0 24 24" class="hidden size-[18px]" fill="none" stroke="currentColor" stroke-width="1.7"><path d="m3 3 18 18M10.6 6.2A10.7 10.7 0 0 1 12 6c6.5 0 10 6 10 6a18 18 0 0 1-3 3.7M6.5 6.5C3.5 8.3 2 12 2 12s3.5 6 10 6a10 10 0 0 0 3-.4"/></svg>
                                </button>
                            </span>
                            @error('password')<span class="mt-2 block text-xs text-rose-300">{{ $message }}</span>@enderror
                        </label>
                    </div>

                    <label class="mt-5 flex w-fit cursor-pointer items-center gap-2.5 text-sm text-[#aaa0b8]">
                        <input name="remember" type="checkbox" value="1" @checked(old('remember')) class="size-4 rounded border-white/15 bg-[#272132] text-[#a86be7] accent-[#a86be7] focus:ring-purple-400/20">
                        Запомнить меня
                    </label>

                    <button class="mt-7 h-[52px] w-full rounded-[15px] bg-gradient-to-r from-[#9b62e4] via-[#875fe2] to-[#54bfd7] px-5 font-semibold text-white shadow-[0_14px_35px_rgba(117,72,197,.25)] transition hover:-translate-y-px hover:brightness-110 focus:outline-none focus:ring-4 focus:ring-purple-300/20">Войти</button>

                    @if(app()->environment(['local', 'testing']))
                        <div class="mt-6 rounded-2xl border border-white/[.06] bg-[#292235]/70 px-4 py-3 text-[11px] leading-5 text-[#8f839e]">
                            <p class="font-medium text-[#b2a8be]">Демо-доступ</p>
                            <p class="mt-1">Admin: admin@example.com</p><p>Investor: alexey@example.com</p><p>Пароль: password</p>
                        </div>
                    @endif
                </form>
            </section>
        </div>

        <footer class="px-4 pb-6 text-center text-xs text-[#746983]">© 2026 CEO Money</footer>
    </main>
</body>
</html>
