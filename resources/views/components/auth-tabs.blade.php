@props(['active'])
<nav class="mb-6 flex gap-1 rounded-xl bg-[#EEF5F0] p-1" aria-label="Account">
    @foreach (['login' => 'Sign in', 'register' => 'Register'] as $route => $label)
        <a
            href="{{ route($route) }}"
            @if ($active === $route) aria-current="page" @endif
            class="flex h-11 flex-1 items-center justify-center rounded-[9px] text-sm transition {{ $active === $route ? 'bg-white font-bold text-forest-900 shadow-[0_1px_3px_rgba(15,61,46,0.18)]' : 'font-semibold text-muted hover:text-forest-900' }}"
        >{{ $label }}</a>
    @endforeach
</nav>
