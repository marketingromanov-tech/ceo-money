@php $compact = isset($compactChart) && $compactChart; $chartHeight = $compact ? 160 : 220; @endphp
<div class="mt-5 overflow-hidden rounded-2xl bg-[#292235] p-3">
    <svg viewBox="0 0 900 {{ $chartHeight }}" class="w-full {{ $compact ? 'h-36 sm:h-40' : 'h-52' }}" preserveAspectRatio="none" role="img" aria-label="График начислений">
        <defs><linearGradient id="accrual-fill" x1="0" y1="0" x2="0" y2="1"><stop stop-color="#70d7e8" stop-opacity=".32"/><stop offset="1" stop-color="#8d61ed" stop-opacity="0"/></linearGradient></defs>
        @foreach($compact ? [35,70,105,140] : [45,90,135,180] as $y)<line x1="0" y1="{{ $y }}" x2="900" y2="{{ $y }}" stroke="white" stroke-opacity=".06"/>@endforeach
        @if($chartPoints)
            <polygon points="10,{{ $chartHeight - 10 }} {{ $chartPoints }} 890,{{ $chartHeight - 10 }}" fill="url(#accrual-fill)"/>
            <polyline points="{{ $chartPoints }}" fill="none" stroke="#70d7e8" stroke-width="3" vector-effect="non-scaling-stroke"/>
            @foreach(explode(' ', $chartPoints) as $index => $coordinates)
                @php [$cx, $cy] = array_pad(explode(',', $coordinates), 2, 0); $chartItem = $series[$index] ?? null; @endphp
                <circle cx="{{ $cx }}" cy="{{ $cy }}" r="{{ $compact ? 4 : 3.5 }}" fill="#292235" stroke="#70d7e8" stroke-width="2" vector-effect="non-scaling-stroke"><title>{{ $chartItem ? \Carbon\Carbon::parse($chartItem['date'])->format('d.m.Y').' · '.\App\Support\MoneyFormatter::format($chartItem['amount']).' USDT' : '' }}</title></circle>
            @endforeach
        @endif
    </svg>
    <div class="mt-2 flex justify-between text-[10px] text-[#827397]"><span>{{ $from->format('d.m') }}</span>@if($from->diffInDays($to) > 2)<span>{{ $from->copy()->addDays(intdiv((int) $from->diffInDays($to), 2))->format('d.m') }}</span>@endif<span>{{ $to->format('d.m') }}</span></div>
</div>
