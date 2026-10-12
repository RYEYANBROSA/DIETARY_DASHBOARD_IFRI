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
<body class="min-h-screen bg-canvas font-sans text-ink antialiased">
<div class="flex min-h-screen">
    {{-- Brand panel - desktop only --}}
    <aside class="hidden w-[44%] max-w-[620px] shrink-0 flex-col justify-between bg-forest-900 px-14 py-12 text-mint-100 lg:flex">
        <div class="flex items-center gap-3">
            <span class="flex size-11 items-center justify-center rounded-xl bg-green-500 text-forest-900"><x-icon name="leaf" size="24" /></span>
            <div class="flex flex-col">
                <strong class="text-[17px] text-white">Dietary Dashboard</strong>
                <span class="text-[13px] text-mint-200">DOST-FNRI</span>
            </div>
        </div>

        <div class="flex flex-col gap-6">
            <span class="text-[13px] font-bold tracking-[0.08em] text-mint-300">DIETARY SURVEY MONITORING</span>
            <h1 class="text-[42px] leading-[1.12] font-extrabold text-white">Dietary survey data, from the field to one dashboard.</h1>
            <p class="text-base leading-relaxed text-mint-100">Track household coverage, encode F11 members and review F72 food recall records, each module opened to the people who need it.</p>
            <ul class="mt-2 flex flex-col gap-3 text-[15px] text-mint-100">
                <li class="flex items-center gap-3"><span class="flex size-8 items-center justify-center rounded-lg bg-forest-800 text-mint-300"><x-icon name="shield" size="18" /></span>Access by level: Client, Validator, Administrator</li>
                <li class="flex items-center gap-3"><span class="flex size-8 items-center justify-center rounded-lg bg-forest-800 text-mint-300"><x-icon name="mail" size="18" /></span>Email-verified accounts</li>
                <li class="flex items-center gap-3"><span class="flex size-8 items-center justify-center rounded-lg bg-forest-800 text-mint-300"><x-icon name="database" size="18" /></span>Reads the dietary survey database directly</li>
            </ul>
        </div>

        <span class="text-[13px] text-mint-200">Food and Nutrition Research Institute · Department of Science and Technology</span>
    </aside>

    <main class="flex flex-1 items-center justify-center px-4 py-10 sm:px-8">
        <div class="w-full max-w-[580px]">
            <div class="mb-6 flex items-center gap-3 lg:hidden">
                <span class="flex size-10 items-center justify-center rounded-xl bg-forest-900 text-green-400"><x-icon name="leaf" size="22" /></span>
                <strong class="text-[17px]">Dietary Dashboard</strong>
            </div>
            <div class="rounded-3xl border border-line bg-white px-6 py-8 shadow-[0_24px_48px_-24px_rgba(15,61,46,0.25)] sm:px-10 sm:py-9">
                {{ $slot }}
            </div>
        </div>
    </main>
</div>
<x-toaster />
@livewireScripts
</body>
</html>
