<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Сессия истекла — CEO Money</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen overflow-x-hidden bg-[#21192e] text-white">
    <main class="relative isolate flex min-h-screen items-center justify-center overflow-hidden px-4 py-10 sm:px-6">
        <div aria-hidden="true" class="pointer-events-none absolute inset-0 -z-20 bg-[radial-gradient(circle_at_20%_20%,rgba(172,106,236,.18),transparent_34%),radial-gradient(circle_at_82%_76%,rgba(76,190,215,.12),transparent_30%),linear-gradient(135deg,#21192e_0%,#281f38_50%,#1d1728_100%)]"></div>
        <div aria-hidden="true" class="pointer-events-none absolute -left-24 top-1/3 -z-10 size-72 rounded-full border border-purple-300/[.07]"></div>
        <div aria-hidden="true" class="pointer-events-none absolute -right-20 bottom-0 -z-10 size-64 rounded-full border border-cyan-200/[.06]"></div>

        <section class="w-full max-w-[520px] rounded-[24px] border border-white/[.1] bg-[#332b42]/90 p-6 text-center shadow-[0_30px_90px_rgba(8,5,14,.4),0_0_70px_rgba(147,88,224,.08)] backdrop-blur-xl sm:p-10">
            <div class="mx-auto flex size-14 items-center justify-center rounded-[18px] border border-white/10 bg-gradient-to-br from-[#bb7ff5] via-[#9a68e9] to-[#55bfd8] text-lg font-black shadow-[0_16px_45px_rgba(139,82,218,.25)]">CM</div>

            <p class="mt-8 bg-gradient-to-r from-[#d7b8f6] to-[#78d5e5] bg-clip-text text-7xl font-semibold tracking-[-.06em] text-transparent sm:text-8xl">419</p>
            <h1 class="mt-4 text-2xl font-semibold tracking-tight sm:text-3xl">Сессия истекла</h1>
            <p class="mx-auto mt-4 max-w-md text-sm leading-6 text-[#a99db7] sm:text-base sm:leading-7">В целях безопасности ваша сессия была завершена. Обновите страницу и войдите снова.</p>

            <div class="mt-8 grid gap-3 sm:grid-cols-2">
                <a href="{{ route('login') }}" class="flex h-[50px] items-center justify-center rounded-[15px] bg-gradient-to-r from-[#9b62e4] via-[#875fe2] to-[#54bfd7] px-5 text-sm font-semibold shadow-[0_14px_35px_rgba(117,72,197,.24)] transition hover:-translate-y-px hover:brightness-110">Вернуться ко входу</a>
                <button type="button" onclick="window.location.reload()" class="h-[50px] rounded-[15px] border border-white/[.09] bg-[#292235] px-5 text-sm font-medium text-[#d1c8da] transition hover:border-cyan-200/20 hover:bg-white/[.05]">Обновить страницу</button>
            </div>

            <p class="mt-8 text-xs text-[#756a83]">© 2026 CEO Money</p>
        </section>
    </main>
</body>
</html>
