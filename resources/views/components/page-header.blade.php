@props(['title', 'subtitle' => null])
<header class="flex flex-wrap items-center justify-between gap-4">
    <div class="flex flex-col gap-1">
        <h1 class="text-[26px] font-extrabold">{{ $title }}</h1>
        @if ($subtitle)
            <p class="text-sm text-muted">{{ $subtitle }}</p>
        @endif
    </div>
    @if ($slot->isNotEmpty())
        <div class="flex flex-wrap items-center gap-2.5">{{ $slot }}</div>
    @endif
</header>
