<article class="overflow-hidden rounded-[20px] border border-cyan-300/15 bg-[#3c354a]">
    <header class="border-b border-white/[.06] bg-[#332c42] px-5 py-4">
        <h3 class="text-sm font-semibold text-white">Эффективные условия</h3>
        <p class="mt-1 text-[10px] text-[#9080ba]">Условия, которые будут применены к новой инвестиции</p>
    </header>
    <dl class="grid gap-px bg-white/[.05] sm:grid-cols-2 xl:grid-cols-4">
        @foreach([
            ['Текущий источник', $effectiveTermsDisplay['source']],
            ['Ставка', $effectiveTermsDisplay['rate'] === null ? '—' : \App\Support\InvestmentTermPresentation::rate($effectiveTermsDisplay['rate'])],
            ['Срок', $effectiveTermsDisplay['term_months'] === null ? '—' : $effectiveTermsDisplay['term_months'].' месяцев'],
            ['Статус', $effectiveTermsDisplay['status']],
        ] as [$label, $value])
            <div class="bg-[#3c354a] p-4"><dt class="text-[10px] text-[#9080ba]">{{ $label }}</dt><dd class="mt-1 text-sm font-semibold text-white">{{ $value }}</dd></div>
        @endforeach
    </dl>
</article>
