@props(['href' => '#', 'label' => '', 'active' => ''])

@php
    $isActive = request()->routeIs($active) || request()->fullUrlIs($href)
@endphp

<a href="{{ $href }}"
   class="block no-underline">
    <div @class([
        'relative flex items-center gap-3 px-3 py-[10px] rounded-xl text-sm font-medium select-none cursor-pointer transition-all duration-200',
        'bg-white/[0.12] text-white' => $isActive,
        'text-slate-400 hover:text-white hover:bg-white/[0.07]' => !$isActive,
    ])>
        @if($isActive)
            <span class="absolute left-0 top-2 bottom-2 w-[3px] bg-[#C9A84C] rounded-r-full pointer-events-none"></span>
        @endif
        <span class="flex-shrink-0 transition-colors duration-200" @class(['text-[#C9A84C]' => $isActive])>
            {{ $slot ?? '' }}
        </span>
        <span class="truncate">{{ $label }}</span>
    </div>
</a>
