<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ isset($title) ? $title . ' · ' : '' }}Dietary Dashboard</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
@php($user = auth()->user())
<body class="min-h-screen bg-canvas font-sans text-ink antialiased" x-data="{ nav: false }">
<div class="flex min-h-screen">
    {{-- Sidebar: fixed on desktop, slide-over on small screens --}}
    <div x-show="nav" x-cloak x-transition.opacity class="fixed inset-0 z-30 bg-forest-950/50 lg:hidden" x-on:click="nav = false"></div>
    <aside
        class="fixed inset-y-0 left-0 z-40 flex w-[264px] -translate-x-full flex-col gap-7 bg-forest-900 px-4 py-6 text-mint-100 transition-transform lg:sticky lg:top-0 lg:h-screen lg:translate-x-0"
        x-bind:class="nav && 'translate-x-0'"
    >
        <div class="flex items-center gap-3 px-1.5">
            <span class="flex size-10 items-center justify-center rounded-[11px] bg-green-500 text-forest-900"><x-icon name="leaf" size="22" /></span>
            <div class="flex flex-col">
                <strong class="text-base text-white">Dietary Dashboard</strong>
                <span class="text-[12.5px] text-mint-200">DOST-FNRI</span>
            </div>
            <button type="button" class="ml-auto flex size-10 items-center justify-center rounded-lg text-mint-100 lg:hidden" x-on:click="nav = false" aria-label="Close menu"><x-icon name="x" /></button>
        </div>

        <nav aria-label="Modules" class="flex flex-col gap-1">
            <span class="px-3.5 pb-2 text-[11.5px] font-bold tracking-[0.1em] text-[#86B79C]">MODULES</span>
            @foreach (\App\Support\Modules::ALL as $key => $module)
                @if ($user->canAccess($key))
                    @php($active = request()->routeIs($module['route']))
                    <a
                        href="{{ route($module['route']) }}"
                        @if ($active) aria-current="page" @endif
                        class="flex min-h-11 items-center gap-3 rounded-[10px] px-3.5 text-sm transition {{ $active ? 'bg-forest-700 font-bold text-white' : 'font-medium text-mint-100 hover:bg-forest-800 hover:text-white' }}"
                    >
                        <x-icon :name="$module['icon']" />
                        {{ $module['label'] }}
                    </a>
                @endif
            @endforeach
        </nav>

        <div class="mt-auto flex flex-col gap-2.5 rounded-[14px] bg-forest-800 p-3.5">
            <div class="flex items-center gap-2.5">
                <span class="flex size-[38px] shrink-0 items-center justify-center rounded-full bg-mint-300 text-sm font-extrabold text-forest-900">{{ $user->initials }}</span>
                <div class="flex min-w-0 flex-col">
                    <strong class="truncate text-sm text-white">{{ $user->name }}</strong>
                    <span class="text-[12.5px] text-mint-200">{{ $user->level->label() }}</span>
                </div>
            </div>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="flex min-h-10 w-full items-center gap-2 rounded-[9px] bg-forest-900 px-2.5 text-[13.5px] font-semibold text-mint-100 hover:text-white">
                    <x-icon name="logout" size="18" /> Sign out
                </button>
            </form>
        </div>
    </aside>

    <div class="flex min-w-0 flex-1 flex-col">
        {{-- Mobile top bar --}}
        <header class="sticky top-0 z-20 flex items-center gap-3 border-b border-line bg-white px-4 py-3 lg:hidden">
            <button type="button" class="flex size-11 items-center justify-center rounded-xl border border-line" x-on:click="nav = true" aria-label="Open menu"><x-icon name="menu" /></button>
            <strong class="text-base">Dietary Dashboard</strong>
        </header>

        <main class="flex flex-1 flex-col gap-6 px-4 py-6 sm:px-8 sm:py-7">
            {{ $slot }}
        </main>
    </div>
</div>
<x-toaster />
@livewireScripts
</body>
</html>
