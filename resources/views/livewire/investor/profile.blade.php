@php use App\Support\InvestorPresentation; @endphp
<div class="mx-auto max-w-[1200px] space-y-5">
    <div><h2 class="text-2xl font-semibold">Профиль</h2><p class="mt-1 text-sm text-[#9587a7]">Личные данные и безопасность аккаунта</p></div>

    <section class="flex flex-col gap-4 rounded-[20px] border border-white/[.07] bg-[#3c354a] p-5 sm:flex-row sm:items-center sm:p-6">
        <div class="flex size-16 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-[#b66ff0] to-[#4d98dc] text-2xl font-semibold">{{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}</div>
        <div class="min-w-0 flex-1"><div class="flex flex-wrap items-center gap-2"><h3 class="truncate text-xl font-semibold">{{ $user->name }}</h3><span class="rounded-full bg-emerald-300/10 px-2.5 py-1 text-[10px] text-emerald-300">{{ InvestorPresentation::status($investor->status) }}</span></div><p class="mt-1 truncate text-sm text-[#a99abc]">{{ $user->email }}</p><p class="mt-1 text-xs text-cyan-200">{{ $investor->code ?: 'Код не назначен' }}</p></div>
    </section>

    <div class="grid gap-5 lg:grid-cols-[1.15fr_.85fr]">
        <section class="rounded-[20px] border border-white/[.07] bg-[#3c354a] p-5 sm:p-6">
            <h3 class="font-semibold">Личные данные</h3><p class="mt-1 text-xs text-[#9183a2]">Вы можете изменить имя и номер телефона</p>
            @if(session('profileSuccess'))<div class="mt-4 rounded-xl border border-emerald-300/20 bg-emerald-400/10 px-3 py-2 text-sm text-emerald-200">{{ session('profileSuccess') }}</div>@endif
            <form wire:submit="saveProfile" class="mt-5 space-y-4">
                <label class="block text-xs text-[#a99abc]">Имя<input wire:model="name" class="mt-1 w-full rounded-xl border border-white/10 bg-[#292235] px-3 py-3 text-white focus:border-cyan-300/40 focus:outline-none"></label>@error('name')<p class="-mt-2 text-xs text-rose-300">{{ $message }}</p>@enderror
                <label class="block text-xs text-[#a99abc]">Телефон<input wire:model="phone" class="mt-1 w-full rounded-xl border border-white/10 bg-[#292235] px-3 py-3 text-white focus:border-cyan-300/40 focus:outline-none"></label>@error('phone')<p class="-mt-2 text-xs text-rose-300">{{ $message }}</p>@enderror
                <label class="block text-xs text-[#a99abc]">Email<input value="{{ $user->email }}" readonly aria-readonly="true" class="mt-1 w-full cursor-not-allowed rounded-xl border border-white/[.06] bg-[#282231]/70 px-3 py-3 text-[#9b8fa7]"></label>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="text-xs text-[#a99abc]">Код инвестора<input value="{{ $investor->code ?: '—' }}" readonly aria-readonly="true" class="mt-1 w-full cursor-not-allowed rounded-xl border border-white/[.06] bg-[#282231]/70 px-3 py-3 text-[#9b8fa7]"></label>
                    <label class="text-xs text-[#a99abc]">Номер договора<input value="{{ $investor->contract_number ?: '—' }}" readonly aria-readonly="true" class="mt-1 w-full cursor-not-allowed rounded-xl border border-white/[.06] bg-[#282231]/70 px-3 py-3 text-[#9b8fa7]"></label>
                    <label class="text-xs text-[#a99abc]">Дата договора<input value="{{ $investor->contract_date?->format('d.m.Y') ?: '—' }}" readonly aria-readonly="true" class="mt-1 w-full cursor-not-allowed rounded-xl border border-white/[.06] bg-[#282231]/70 px-3 py-3 text-[#9b8fa7]"></label>
                    <label class="text-xs text-[#a99abc]">Дата регистрации<input value="{{ $user->created_at->format('d.m.Y') }}" readonly aria-readonly="true" class="mt-1 w-full cursor-not-allowed rounded-xl border border-white/[.06] bg-[#282231]/70 px-3 py-3 text-[#9b8fa7]"></label>
                </div>
                <button type="submit" class="cursor-pointer rounded-xl bg-gradient-to-r from-[#8d61ed] to-[#4ebdd5] px-5 py-3 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50">Сохранить изменения</button>
            </form>
        </section>

        <div class="space-y-5">
            <section id="security" class="scroll-mt-24 rounded-[20px] border border-white/[.07] bg-[#3c354a] p-5 sm:p-6">
                <h3 class="font-semibold">Безопасность</h3><p class="mt-1 text-xs text-[#9183a2]">Изменение пароля аккаунта</p>
                @if(session('passwordSuccess'))<div class="mt-4 rounded-xl border border-emerald-300/20 bg-emerald-400/10 px-3 py-2 text-sm text-emerald-200">{{ session('passwordSuccess') }}</div>@endif
                <form wire:submit="changePassword" class="mt-5 space-y-4">
                    <label class="block text-xs text-[#a99abc]">Текущий пароль<input type="password" wire:model="currentPassword" autocomplete="current-password" class="mt-1 w-full rounded-xl border border-white/10 bg-[#292235] px-3 py-3 text-white focus:border-cyan-300/40 focus:outline-none"></label>@error('currentPassword')<p class="-mt-2 text-xs text-rose-300">{{ $message }}</p>@enderror
                    <label class="block text-xs text-[#a99abc]">Новый пароль<input type="password" wire:model="newPassword" autocomplete="new-password" class="mt-1 w-full rounded-xl border border-white/10 bg-[#292235] px-3 py-3 text-white focus:border-cyan-300/40 focus:outline-none"></label>@error('newPassword')<p class="-mt-2 text-xs text-rose-300">{{ $message }}</p>@enderror
                    <label class="block text-xs text-[#a99abc]">Повторите новый пароль<input type="password" wire:model="newPasswordConfirmation" autocomplete="new-password" class="mt-1 w-full rounded-xl border border-white/10 bg-[#292235] px-3 py-3 text-white focus:border-cyan-300/40 focus:outline-none"></label>@error('newPasswordConfirmation')<p class="-mt-2 text-xs text-rose-300">{{ $message }}</p>@enderror
                    <button type="submit" class="w-full cursor-pointer rounded-xl border border-purple-300/20 bg-purple-300/10 px-4 py-3 text-sm font-semibold text-purple-100 hover:bg-purple-300/15">Изменить пароль</button>
                </form>
            </section>

            <section class="rounded-[20px] border border-white/[.07] bg-[#3c354a] p-5 sm:p-6"><h3 class="font-semibold">Информация об аккаунте</h3><dl class="mt-4 space-y-3 text-sm">@foreach([['Статус аккаунта', $user->is_active ? 'Активен' : 'Неактивен'], ['Код инвестора', $investor->code ?: '—'], ['Дата регистрации', $user->created_at->format('d.m.Y')], ['Последнее обновление профиля', $user->updated_at->format('d.m.Y H:i')]] as [$label, $value])<div class="flex items-start justify-between gap-4 border-b border-white/[.05] pb-3 last:border-0 last:pb-0"><dt class="text-xs text-[#9183a2]">{{ $label }}</dt><dd class="text-right text-xs font-medium">{{ $value }}</dd></div>@endforeach</dl></section>
        </div>
    </div>
</div>
