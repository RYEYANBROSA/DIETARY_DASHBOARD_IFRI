{{--
    Sync Data (administrators only), from SurveySync::overview(): each survey table's row count here and on
    the live server, its last fetch, and buttons to fetch it now. The page refreshes itself every minute.
--}}
@php($runLabel = fn ($run) => $run->trigger === 'auto' ? 'Auto-fetch' : ($run->user?->name ?? 'Command line'))
<div class="flex flex-col gap-6" wire:poll.60s>
    <x-page-header title="Sync Data" subtitle="Copies the survey tables from the live server, where the tablets transmit, into this system. The live server is only read, never changed.">
        <button type="button" wire:loading.attr="disabled" wire:target="fetchAll, fetch" @disabled(!$overview['reachable'])
            x-on:click="busyAlert('Fetching every table…', 'Copying from the live server. This can take a minute.', $wire.fetchAll())"
            class="flex h-11 items-center gap-2 rounded-[11px] bg-green-700 px-4 text-sm font-bold text-white hover:bg-green-800 disabled:opacity-60">
            <x-icon name="sync" size="18" /> Fetch all tables
        </button>
    </x-page-header>

    {{-- Live server and auto-fetch status --}}
    <div class="grid gap-3 md:grid-cols-2">
        <div class="flex items-start gap-3 rounded-2xl border px-[18px] py-4 {{ $overview['reachable'] ? 'border-line bg-white' : 'border-red-200 bg-red-50' }}">
            <span class="mt-1.5 size-2.5 shrink-0 rounded-full {{ $overview['reachable'] ? 'bg-green-600' : 'bg-red-600' }}"></span>
            <div class="flex flex-col gap-0.5">
                <span class="text-sm font-bold">{{ $overview['reachable'] ? 'Live server connected' : "Live server can't be reached" }}</span>
                <span class="text-[12.5px] text-muted">{{ config('database.connections.' . \App\Support\SurveySync::CONNECTION . '.database') }} on {{ config('database.connections.' . \App\Support\SurveySync::CONNECTION . '.host') }}{{ $overview['reachable'] ? '' : ' - live counts and fetching are unavailable until it answers.' }}</span>
            </div>
        </div>
        <div class="flex items-start gap-3 rounded-2xl border px-[18px] py-4 {{ $autoLate ? 'border-amber-200 bg-amber-50' : 'border-line bg-white' }}">
            <x-icon name="sync" size="20" class="mt-0.5 shrink-0 {{ $autoLate ? 'text-amber-700' : 'text-green-700' }}" />
            <div class="flex flex-col gap-0.5">
                <span class="text-sm font-bold">Auto-fetch every {{ \App\Support\SurveySync::AUTO_MINUTES }} minutes</span>
                @if ($lastAuto)
                    <span class="text-[12.5px] {{ $autoLate ? 'text-amber-900' : 'text-muted' }}">
                        Last ran {{ $lastAuto->created_at->diffForHumans() }} ({{ $lastAuto->created_at->format('M j, g:i A') }}).
                        @if ($autoLate) It is overdue - check that the scheduler is still running. @endif
                    </span>
                @else
                    <span class="text-[12.5px] text-amber-900">Hasn't run yet. The server needs the Laravel scheduler: <code class="font-mono">php artisan schedule:run</code> every minute (cron or Windows Task Scheduler), or <code class="font-mono">php artisan schedule:work</code> left running.</span>
                @endif
            </div>
        </div>
    </div>

    {{-- Tables --}}
    <section class="flex min-w-0 flex-col gap-3 rounded-2xl border border-line bg-white p-[18px]">
        <div class="flex flex-wrap items-baseline justify-between gap-3">
            <h2 class="text-[17px] font-bold">Tables</h2>
            <span class="text-[12.5px] text-muted"><strong>Fetch</strong> adds the live rows that aren't here yet - nothing here is updated or removed</span>
        </div>
        <div class="relative overflow-x-auto rounded-xl border border-line">
            <div wire:loading.delay wire:target="fetch, fetchAll" class="absolute inset-0 z-10 bg-white/60"></div>
            <table class="w-full border-collapse text-[13.5px]">
                <thead class="bg-[#F6FAF7] text-xs font-bold text-muted">
                    <tr>
                        <th class="px-3.5 py-3 text-left whitespace-nowrap">Table</th>
                        <th class="px-3.5 py-3 text-right whitespace-nowrap">Local rows</th>
                        <th class="px-3.5 py-3 text-right whitespace-nowrap">Live rows</th>
                        <th class="px-3.5 py-3 text-left whitespace-nowrap">In step</th>
                        <th class="px-3.5 py-3 text-left whitespace-nowrap">Last fetch</th>
                        <th class="px-3.5 py-3 text-right whitespace-nowrap"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($overview['tables'] as $t)
                        @php($diff = $t['local'] !== null && $t['live'] !== null ? $t['live'] - $t['local'] : null)
                        <tr wire:key="sync-{{ $t['table'] }}" class="border-t border-[#EEF3EF] align-top">
                            <td class="px-3.5 py-3">
                                <span class="block font-mono text-[13px] font-bold">{{ $t['table'] }}</span>
                                <span class="block text-[12.5px] text-muted">{{ $t['label'] }}</span>
                            </td>
                            <td class="px-3.5 py-3 text-right font-semibold whitespace-nowrap">{{ $t['local'] === null ? '—' : number_format($t['local']) }}</td>
                            <td class="px-3.5 py-3 text-right font-semibold whitespace-nowrap">{{ $t['live'] === null ? '—' : number_format($t['live']) }}</td>
                            <td class="px-3.5 py-3 whitespace-nowrap">
                                @if (!$t['fetchable'])
                                    <span class="rounded-full bg-[#EEF3EF] px-2.5 py-0.5 text-xs font-bold text-ink-soft">Not fetched</span>
                                @elseif ($diff === null)
                                    <span class="text-[12.5px] text-muted">{{ $t['live'] === null ? 'Live count unavailable' : 'Not here yet' }}</span>
                                @elseif ($diff === 0)
                                    <span class="rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-bold text-green-900">Same count</span>
                                @else
                                    <span class="rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-bold text-amber-900">{{ $diff > 0 ? number_format($diff) . ' to fetch' : number_format(-$diff) . ' more here' }}</span>
                                @endif
                            </td>
                            <td class="px-3.5 py-3 text-[12.5px]">
                                @if (!$t['fetchable'])
                                    <span class="text-muted">Goes the other way: sent from here to the live server by <strong>Transmit</strong> in SWS Upload Data.</span>
                                @elseif ($t['last'])
                                    @php($last = $t['last'])
                                    <span class="block">
                                        <span class="rounded-full px-2 py-0.5 text-[11.5px] font-bold {{ $last->status === 'ok' ? 'bg-green-100 text-green-900' : 'bg-red-100 text-red-800' }}">{{ $last->status === 'ok' ? 'OK' : 'Failed' }}</span>
                                        <span class="ml-1 font-semibold">{{ $last->created_at->diffForHumans() }}</span>
                                        <span class="text-muted">· {{ $runLabel($last) }}</span>
                                    </span>
                                    <span class="mt-0.5 block text-muted">
                                        @if ($last->status === 'ok')
                                            {{ number_format($last->fetched) }} added{{ $last->retained ? ' · ' . number_format($last->retained) . ' kept (deleted on live)' : '' }} · {{ $last->mode }} · {{ number_format($last->duration_ms / 1000, 1) }}s
                                        @else
                                            {{ $last->message }}
                                        @endif
                                    </span>
                                    @if ($last->status !== 'ok' && $t['lastOk'])
                                        <span class="block text-muted">Last good fetch {{ $t['lastOk']->created_at->diffForHumans() }}</span>
                                    @endif
                                @else
                                    <span class="text-muted">Never fetched</span>
                                @endif
                            </td>
                            <td class="px-3.5 py-3 text-right whitespace-nowrap">
                                @if ($t['fetchable'])
                                    <button type="button" wire:loading.attr="disabled" wire:target="fetch, fetchAll" @disabled(!$overview['reachable'])
                                        x-on:click="busyAlert('Fetching {{ $t['table'] }}…', 'Adding the new rows from the live server.', $wire.fetch('{{ $t['table'] }}'))"
                                        class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-green-700 px-3 text-[12.5px] font-bold text-white hover:bg-green-800 disabled:opacity-50">
                                        <x-icon name="download" size="15" /> Fetch
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-10 text-center text-muted">The survey database can't be read right now.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p class="text-[12px] text-muted">A fetch <strong>only adds</strong>: live rows that aren't here yet are added (matched by area, household and member). A row already here is never updated, even if it changed on the live server, and a row deleted on the live server is kept - so "more here" means rows the live server no longer has. Counts refresh every minute.</p>
    </section>

    {{-- Recent fetches --}}
    <section class="flex min-w-0 flex-col gap-3 rounded-2xl border border-line bg-white p-[18px]">
        <h2 class="text-[17px] font-bold">Recent fetches</h2>
        <div class="overflow-x-auto rounded-xl border border-line">
            <table class="w-full border-collapse text-[13px]">
                <thead class="bg-[#F6FAF7] text-xs font-bold text-muted">
                    <tr>
                        @foreach (['When', 'Table', 'By', 'Mode', 'Result', 'Added', 'Kept (deleted on live)', 'Live / local', 'Took'] as $heading)
                            <th class="px-3 py-2.5 text-left whitespace-nowrap">{{ $heading }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse ($runs as $run)
                        <tr wire:key="run-{{ $run->id }}" class="border-t border-[#EEF3EF]">
                            <td class="px-3 py-2 whitespace-nowrap">{{ $run->created_at->format('M j, g:i:s A') }}</td>
                            <td class="px-3 py-2 font-mono text-[12.5px] whitespace-nowrap">{{ $run->table }}</td>
                            <td class="px-3 py-2 whitespace-nowrap">{{ $runLabel($run) }}</td>
                            <td class="px-3 py-2 whitespace-nowrap">{{ $run->mode }}</td>
                            <td class="px-3 py-2">
                                <span class="rounded-full px-2 py-0.5 text-[11.5px] font-bold {{ $run->status === 'ok' ? 'bg-green-100 text-green-900' : 'bg-red-100 text-red-800' }}">{{ $run->status === 'ok' ? 'OK' : 'Failed' }}</span>
                                @if ($run->status !== 'ok')<span class="ml-1 text-[12px] text-red-800">{{ $run->message }}</span>@endif
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap">{{ number_format($run->fetched) }}</td>
                            <td class="px-3 py-2 whitespace-nowrap">{{ number_format($run->retained) }}</td>
                            <td class="px-3 py-2 whitespace-nowrap">{{ $run->live_count === null ? '—' : number_format($run->live_count) }} / {{ $run->local_count === null ? '—' : number_format($run->local_count) }}</td>
                            <td class="px-3 py-2 whitespace-nowrap">{{ number_format($run->duration_ms / 1000, 1) }}s</td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="px-4 py-8 text-center text-muted">No fetches yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
