{{--
    24-hr food recall monitoring (Dashboard > 24-hr recall monitoring), from RecallMonitor::report().
    Completed = interview_status 1-2, refused = 3 and above; every rate is out of the SWS respondents in the area.
--}}
@php
    $select = 'h-[42px] min-w-[130px] rounded-[10px] border border-field bg-white px-2.5 text-sm font-normal text-ink disabled:bg-canvas';
    $pct = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format($v, 1), '0'), '.') . '%';
    $completionTone = fn ($v) => $v === null ? 'bg-[#EEF3EF] text-ink-soft' : ($v >= 80 ? 'bg-green-100 text-green-900' : ($v >= 50 ? 'bg-amber-100 text-amber-900' : 'bg-red-100 text-red-800'));
    $refusalTone = fn ($v) => $v === null ? 'bg-[#EEF3EF] text-ink-soft' : ($v <= 5 ? 'bg-green-100 text-green-900' : ($v <= 15 ? 'bg-amber-100 text-amber-900' : 'bg-red-100 text-red-800'));
    $areaLevels = ['Province', 'Municipality / district', 'Barangay'];
@endphp

@if ($recallMissing)
    <div class="flex items-start gap-3 rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 text-amber-900">
        <x-icon name="database" class="mt-0.5" />
        <div class="flex flex-col gap-1 text-sm">
            <strong>The monitoring table (f72_interview_status) isn't there yet.</strong>
            <span>Figures will appear once the field researchers' interview statuses are transmitted.</span>
        </div>
    </div>
@elseif ($recallState === 'unreachable' || !$recall)
    <div class="flex flex-wrap items-start justify-between gap-3 rounded-2xl border border-red-200 bg-red-50 px-5 py-4 text-red-900">
        <div class="flex items-start gap-3">
            <x-icon name="alert" class="mt-0.5" />
            <div class="flex flex-col gap-1 text-sm">
                <strong>The live server can't be reached right now.</strong>
                <span>The 24-hr recall figures come from the live server ({{ config('database.connections.' . \App\Support\RecallMonitor::connectionName() . '.host') }}). Check that this computer is on the office network or VPN, then try again.</span>
            </div>
        </div>
        <button type="button" wire:click="refreshLive" wire:loading.attr="disabled" wire:target="refreshLive" class="h-10 rounded-[10px] border border-red-300 bg-white px-4 text-sm font-bold text-red-800 hover:bg-red-100 disabled:opacity-60">
            <span wire:loading.remove wire:target="refreshLive">Try again</span><span wire:loading wire:target="refreshLive">Checking…</span>
        </button>
    </div>
@else
    {{-- Filters --}}
    <div class="flex flex-col gap-3 rounded-2xl border border-line bg-white p-[18px]">
        <div class="flex flex-wrap items-end gap-2.5">
            <label class="flex flex-col gap-1.5 text-[12.5px] font-semibold text-ink-soft">Transmitted from
                <input type="date" wire:model.live="from" min="{{ $options['firstDate'] }}" max="{{ $to ?: $options['lastDate'] }}" class="h-[42px] rounded-[10px] border border-field bg-white px-2.5 text-sm font-normal text-ink">
            </label>
            <label class="flex flex-col gap-1.5 text-[12.5px] font-semibold text-ink-soft">to
                <input type="date" wire:model.live="to" min="{{ $from ?: $options['firstDate'] }}" max="{{ $options['lastDate'] }}" class="h-[42px] rounded-[10px] border border-field bg-white px-2.5 text-sm font-normal text-ink">
            </label>
            <label class="flex flex-col gap-1.5 text-[12.5px] font-semibold text-ink-soft">Province
                <select wire:model.live="province" class="{{ $select }}">
                    <option value="">All</option>
                    @foreach ($options['provinces'] as $o)<option value="{{ $o }}">{{ $o }}</option>@endforeach
                </select>
            </label>
            <label class="flex flex-col gap-1.5 text-[12.5px] font-semibold text-ink-soft">Municipality / district
                <select wire:model.live="municipality" @disabled($province === '') class="{{ $select }}">
                    <option value="">All</option>
                    @foreach ($options['municipalities'] as $o)<option value="{{ $o }}">{{ $o }}</option>@endforeach
                </select>
            </label>
            <label class="flex flex-col gap-1.5 text-[12.5px] font-semibold text-ink-soft">Barangay
                <select wire:model.live="barangay" @disabled($municipality === '') class="{{ $select }}">
                    <option value="">All</option>
                    @foreach ($options['barangays'] as $o)<option value="{{ $o }}">{{ $o }}</option>@endforeach
                </select>
            </label>
            <label class="flex flex-col gap-1.5 text-[12.5px] font-semibold text-ink-soft">Researcher
                <select wire:model.live="researcher" class="{{ $select }}">
                    <option value="">All</option>
                    @foreach ($options['researchers'] as $o)<option value="{{ $o }}">{{ $o }}</option>@endforeach
                </select>
            </label>
            <div class="flex flex-col gap-1.5 text-[12.5px] font-semibold text-ink-soft">Recall day
                <div class="flex h-[42px] overflow-hidden rounded-[10px] border border-field" role="group" aria-label="Recall day">
                    @foreach (['' => 'Both', '1' => 'Day 1', '2' => 'Day 2'] as $value => $label)
                        <button type="button" wire:click="$set('day', '{{ $value }}')" @if ($day === (string) $value) aria-pressed="true" @endif
                            class="px-3.5 text-sm font-bold {{ $day === (string) $value ? 'bg-green-700 text-white' : 'bg-white text-ink-soft hover:bg-[#F6FAF7]' }}">{{ $label }}</button>
                    @endforeach
                </div>
            </div>
            <button type="button" wire:click="clearRecallFilters" class="h-[42px] rounded-[10px] border border-field bg-white px-4 text-sm font-bold text-ink-soft hover:border-green-700">Clear</button>
        </div>
        <p class="text-[12.5px] text-muted">
            Rates are out of <strong class="text-ink">{{ number_format($recall['target']) }}</strong> SWS respondent(s){{ ($barangay ?: $municipality ?: $province) !== '' ? ' in ' . ($barangay ?: $municipality ?: $province) : '' }}.
            Completed = interview status 1–2 · refused = 3 and above · {{ number_format($recall['recalls']) }} recall(s) transmitted{{ $researcher ? ' by ' . $researcher : '' }}{{ $from || $to ? ' ' . ($from ? 'from ' . \Illuminate\Support\Carbon::parse($from)->format('M j') : '') . ($to ? ' to ' . \Illuminate\Support\Carbon::parse($to)->format('M j, Y') : '') : '' }}.
        </p>
        <span class="flex flex-wrap items-center gap-1.5 text-[12.5px] text-muted">
                <span class="size-2 rounded-full bg-green-600" aria-hidden="true"></span>
                24-hr recall from the {{ \App\Support\RecallMonitor::connectionName() === 'mysql_live' ? 'live server' : 'local database' }}{{ $recallFetchedAt ? ' · updated ' . $recallFetchedAt->diffForHumans() : '' }}
                <button type="button" wire:click="refreshLive" wire:loading.attr="disabled" wire:target="refreshLive" class="font-bold text-green-800 hover:underline disabled:opacity-60">
                    <span wire:loading.remove wire:target="refreshLive">Refresh</span><span wire:loading wire:target="refreshLive">Refreshing…</span>
                </button>
            </span>
    </div>

    <div class="relative flex flex-col gap-4">
        <div wire:loading.delay class="absolute inset-0 z-10 rounded-2xl bg-canvas/60"></div>

        {{-- Completion and refusal per recall day --}}
        <div class="grid grid-cols-1 gap-4 {{ count($recall['days']) > 1 ? 'xl:grid-cols-3' : 'xl:grid-cols-2' }}">
            @foreach ($recall['days'] as $d)
                <div class="flex flex-col gap-3 rounded-2xl border border-line bg-white p-5">
                    <div class="flex items-center justify-between">
                        <h2 class="text-[17px] font-bold">{{ $d['label'] }}</h2>
                        <span class="text-[12.5px] text-muted">{{ number_format($d['completed'] + $d['refused']) }} of {{ number_format($recall['target']) }} visited</span>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="flex flex-col gap-0.5">
                            <span class="text-[12.5px] font-semibold text-muted">Completion rate</span>
                            <span class="text-[30px] leading-none font-extrabold text-green-800">{{ $pct($d['completion']) }}</span>
                            <span class="text-[12.5px] text-muted">{{ number_format($d['completed']) }} completed</span>
                        </div>
                        <div class="flex flex-col gap-0.5">
                            <span class="text-[12.5px] font-semibold text-muted">Refusal rate</span>
                            <span class="text-[30px] leading-none font-extrabold text-red-700">{{ $pct($d['refusal']) }}</span>
                            <span class="text-[12.5px] text-muted">{{ number_format($d['refused']) }} refused</span>
                        </div>
                    </div>
                    @php($t = max(1, $recall['target']))
                    <span class="flex h-3 overflow-hidden rounded-full bg-[#EEF3EF]" role="img" aria-label="{{ $d['completed'] }} completed, {{ $d['refused'] }} refused, {{ $d['notYet'] }} not yet done">
                        <span class="block h-3 bg-green-700" style="width: {{ $d['completed'] / $t * 100 }}%"></span>
                        <span class="block h-3 bg-red-500" style="width: {{ $d['refused'] / $t * 100 }}%"></span>
                    </span>
                    <div class="flex flex-wrap gap-x-4 gap-y-1 text-[12px] text-ink-soft">
                        <span class="flex items-center gap-1.5"><span class="size-2 rounded-full bg-green-700"></span>Completed {{ number_format($d['completed']) }}</span>
                        <span class="flex items-center gap-1.5"><span class="size-2 rounded-full bg-red-500"></span>Refused {{ number_format($d['refused']) }}</span>
                        <span class="flex items-center gap-1.5"><span class="size-2 rounded-full bg-[#CBD5E1]"></span>Not yet done {{ number_format($d['notYet']) }}</span>
                    </div>
                </div>
            @endforeach

            @if (count($recall['days']) > 1)
                <div class="flex flex-col gap-3 rounded-2xl bg-forest-900 p-5 text-white">
                    <h2 class="text-[17px] font-bold">Day 2 follow-up</h2>
                    <span class="text-[30px] leading-none font-extrabold">{{ $pct($recall['followUp']['rate']) }}</span>
                    <span class="block h-2 rounded-full bg-forest-700"><span class="block h-2 rounded-full bg-mint-300" style="width: {{ min(100, $recall['followUp']['rate'] ?? 0) }}%"></span></span>
                    <span class="text-[13px] text-mint-100">{{ number_format($recall['followUp']['done']) }} of {{ number_format($recall['followUp']['of']) }} Day 1 completers have also completed Day 2.</span>
                </div>
            @endif
        </div>

        <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
            {{-- Household survey vs 24-hr recall --}}
            <div class="flex flex-col gap-3 rounded-2xl border border-line bg-white p-[22px]">
                <div class="flex items-baseline justify-between gap-3">
                    <h2 class="text-[17px] font-bold">Household survey vs 24-hr recall</h2>
                    <span class="text-[12.5px] text-muted">same area</span>
                </div>
                @php($rows = [])
                @if ($recall['household'])
                    @php($rows[] = ['Household survey', $recall['household']['completion'], $recall['household']['refusal'], number_format($recall['household']['households']) . ' households'])
                @endif
                @foreach ($recall['days'] as $d)
                    @php($rows[] = ['24-hr recall · ' . $d['label'], $d['completion'], $d['refusal'], number_format($recall['target']) . ' SWS respondents'])
                @endforeach
                @foreach (['Completion', 'Refusal'] as $i => $measure)
                    <span class="mt-1 text-[12px] font-bold tracking-[0.06em] text-muted uppercase">{{ $measure }}</span>
                    @foreach ($rows as [$label, $completion, $refusal, $base])
                        @php($v = $i === 0 ? $completion : $refusal)
                        <div class="grid grid-cols-[160px_minmax(0,1fr)_56px] items-center gap-3 text-[13px]">
                            <span class="truncate text-ink-soft" title="{{ $base }}">{{ $label }}</span>
                            <span class="block h-3 rounded-md bg-[#EEF5F0]"><span class="block h-3 rounded-md {{ $i === 0 ? ($label === 'Household survey' ? 'bg-forest-700' : 'bg-green-600') : ($label === 'Household survey' ? 'bg-red-800' : 'bg-red-500') }}" style="width: {{ min(100, $v ?? 0) }}%"></span></span>
                            <span class="text-right font-bold">{{ $pct($v) }}</span>
                        </div>
                    @endforeach
                @endforeach
                @unless ($recall['household'])
                    <p class="text-[12.5px] text-muted">No household listing for this area yet, so only the 24-hr recall is shown.</p>
                @endunless
                <p class="text-[12px] text-muted">Household survey: covered = status 1–2, refused = status 4, out of listed households.</p>
            </div>

            {{-- Daily trend --}}
            <div class="flex flex-col gap-3 rounded-2xl border border-line bg-white p-[22px]">
                <div class="flex items-baseline justify-between gap-3">
                    <h2 class="text-[17px] font-bold">Recalls per day transmitted</h2>
                    <span class="text-[12.5px] text-muted">completed / refused</span>
                </div>
                @php($maxDay = max(1, collect($recall['trend'])->max(fn ($t) => $t['completed'] + $t['refused'])))
                @forelse ($recall['trend'] as $t)
                    <div class="grid grid-cols-[86px_minmax(0,1fr)_92px] items-center gap-3 text-[13px]">
                        <span class="font-semibold text-ink-soft">{{ \Illuminate\Support\Carbon::parse($t['date'])->format('M j, Y') }}</span>
                        <span class="flex h-3.5 overflow-hidden rounded-md bg-[#EEF5F0]" style="width: {{ ($t['completed'] + $t['refused']) / $maxDay * 100 }}%">
                            <span class="block h-3.5 bg-green-700" style="width: {{ $t['completed'] / max(1, $t['completed'] + $t['refused']) * 100 }}%"></span>
                            <span class="block h-3.5 bg-red-500" style="width: {{ $t['refused'] / max(1, $t['completed'] + $t['refused']) * 100 }}%"></span>
                        </span>
                        <span class="text-right text-[12.5px]"><strong>{{ $t['completed'] }}</strong> / <span class="text-red-700">{{ $t['refused'] }}</span> <span class="text-muted">· D1 {{ $t['1'] }} D2 {{ $t['2'] }}</span></span>
                    </div>
                @empty
                    <p class="text-sm text-muted">No recalls transmitted in this period.</p>
                @endforelse
            </div>
        </div>

        {{-- By researcher --}}
        <div class="flex min-w-0 flex-col gap-3 rounded-2xl border border-line bg-white p-[18px]">
            <div class="flex flex-wrap items-baseline justify-between gap-3">
                <h2 class="text-[17px] font-bold">By researcher</h2>
                <span class="text-[12.5px] text-muted">completion here = completed ÷ recalls the researcher transmitted · click a row to filter</span>
            </div>
            <div class="overflow-x-auto rounded-xl border border-line">
                <table class="w-full border-collapse text-[13px]">
                    <thead class="bg-[#F6FAF7] text-xs font-bold text-muted">
                        <tr>
                            <th rowspan="2" class="px-3 py-2 text-left align-bottom">Researcher</th>
                            @foreach (\App\Support\RecallMonitor::DAYS as $label)<th colspan="4" class="border-l border-line px-3 pt-2 text-center">{{ $label }}</th>@endforeach
                        </tr>
                        <tr>
                            @foreach (\App\Support\RecallMonitor::DAYS as $label)
                                <th class="border-l border-line px-3 py-1.5 text-right">Recalls</th><th class="px-3 py-1.5 text-right">Completed</th><th class="px-3 py-1.5 text-right">Refused</th><th class="px-3 py-1.5 text-right">Completion</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($recall['researchers'] as $r)
                            <tr wire:key="rm-r-{{ md5($r['name']) }}" wire:click="filterResearcher(@js($r['name']))" class="cursor-pointer border-t border-[#EEF3EF] hover:bg-[#F6FAF7] {{ $researcher === $r['name'] ? 'bg-green-50' : '' }}">
                                <td class="px-3 py-2.5 font-bold">{{ $r['name'] }}</td>
                                @foreach (\App\Support\RecallMonitor::DAYS as $dayKey => $label)
                                    <td class="border-l border-[#EEF3EF] px-3 py-2.5 text-right">{{ $r[$dayKey]['recalls'] }}</td>
                                    <td class="px-3 py-2.5 text-right">{{ $r[$dayKey]['completed'] }}</td>
                                    <td class="px-3 py-2.5 text-right text-red-700">{{ $r[$dayKey]['refused'] }}</td>
                                    <td class="px-3 py-2.5 text-right"><span class="rounded-full px-2 py-0.5 text-xs font-bold {{ $completionTone($r[$dayKey]['rate']) }}">{{ $pct($r[$dayKey]['rate']) }}</span></td>
                                @endforeach
                            </tr>
                        @empty
                            <tr><td colspan="9" class="px-4 py-8 text-center text-muted">No recalls match these filters.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- By area --}}
        <div class="flex min-w-0 flex-col gap-3 rounded-2xl border border-line bg-white p-[18px]">
            <div class="flex flex-wrap items-baseline justify-between gap-3">
                <h2 class="text-[17px] font-bold">By {{ strtolower($areaLevels[$recall['areaLevel']]) }}</h2>
                <span class="flex flex-wrap items-center gap-1 text-[12.5px] text-muted">
                    @if ($province !== '')
                        <button type="button" wire:click="$set('province', '')" class="font-bold text-green-800 hover:underline">All</button>
                        <span>›</span>
                        <button type="button" wire:click="$set('municipality', '')" class="font-bold text-green-800 hover:underline">{{ $province }}</button>
                        @if ($municipality !== '')<span>›</span><span class="font-bold text-ink">{{ $municipality }}</span>@endif
                        @if ($barangay !== '')<span>›</span><span class="font-bold text-ink">{{ $barangay }}</span>@endif
                    @else
                        click a row to drill down
                    @endif
                </span>
            </div>
            <div class="overflow-x-auto rounded-xl border border-line">
                <table class="w-full border-collapse text-[13px]">
                    <thead class="bg-[#F6FAF7] text-xs font-bold text-muted">
                        <tr>
                            <th class="px-3 py-2.5 text-left">{{ $areaLevels[$recall['areaLevel']] }}</th>
                            <th class="px-3 py-2.5 text-right">SWS respondents</th>
                            @foreach (\App\Support\RecallMonitor::DAYS as $label)
                                <th class="border-l border-line px-3 py-2.5 text-right">{{ $label }} completed</th><th class="px-3 py-2.5 text-right">Completion</th><th class="px-3 py-2.5 text-right">Refusal</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($recall['areas'] as $a)
                            <tr wire:key="rm-a-{{ md5($a['name']) }}" @if ($barangay === '') wire:click="drillArea(@js($a['name']))" @endif class="border-t border-[#EEF3EF] {{ $barangay === '' ? 'cursor-pointer hover:bg-[#F6FAF7]' : '' }}">
                                <td class="px-3 py-2.5 font-bold">{{ $a['name'] }}</td>
                                <td class="px-3 py-2.5 text-right">{{ number_format($a['target']) }}</td>
                                @foreach (\App\Support\RecallMonitor::DAYS as $dayKey => $label)
                                    <td class="border-l border-[#EEF3EF] px-3 py-2.5 text-right">{{ $a[$dayKey]['completed'] }}</td>
                                    <td class="px-3 py-2.5 text-right"><span class="rounded-full px-2 py-0.5 text-xs font-bold {{ $completionTone($a[$dayKey]['completion']) }}">{{ $pct($a[$dayKey]['completion']) }}</span></td>
                                    <td class="px-3 py-2.5 text-right"><span class="rounded-full px-2 py-0.5 text-xs font-bold {{ $refusalTone($a[$dayKey]['refusal']) }}">{{ $pct($a[$dayKey]['refusal']) }}</span></td>
                                @endforeach
                            </tr>
                        @empty
                            <tr><td colspan="8" class="px-4 py-8 text-center text-muted">No SWS respondents or recalls in this area.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <p class="text-[12px] text-muted">"—" means the area has recalls but no SWS respondents to measure them against.</p>
        </div>
    </div>
@endif
