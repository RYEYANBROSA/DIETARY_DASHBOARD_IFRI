{{--
    Dashboard > Overview (map first), from Overview::build(): SWS households coloured by 24-hr recall status,
    key figures, latest activity, researcher completion, and what people ate.
--}}
@php
    $pct = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format($v, 1), '0'), '.') . '%';
    $statuses = \App\Support\Overview::STATUSES;
    $mealShades = ['#14532D', '#86C99A', '#15803D', '#B7E1C3', '#3E8E53', '#DDF1E2', '#8A9A90'];
@endphp

@if (!$overview)
    <x-survey-offline />
@else
    @php
        $hh = $overview['households'];
        $recall = $overview['recall'];
        $day1 = $recall['days'][1] ?? null;
        $day2 = $recall['days'][2] ?? null;
        $map = $overview['map'];
    @endphp

    <div class="flex flex-wrap items-center justify-between gap-3">
        <label class="flex items-center gap-2 text-[13px] font-semibold text-ink-soft">Area
            <select wire:model.live="province" class="h-10 min-w-[190px] rounded-[10px] border border-field bg-white px-2.5 text-sm font-normal text-ink">
                <option value="">All provinces</option>
                @foreach ($provinces as $o)<option value="{{ $o }}">{{ $o }}</option>@endforeach
            </select>
        </label>
        <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
            @php($lastSent = collect($overview['activity'])->first(fn ($a) => str_starts_with($a['what'], 'Transmitted')))
            @if ($lastSent)
                <span class="text-[12.5px] text-muted">Last SWS transmission · {{ $lastSent['when']->format('M j, g:i A') }}</span>
            @endif
            @php($recallFetchedAt = $overview['recallFetchedAt'])
            @if ($overview['recallState'] === 'ok')
                <span class="flex flex-wrap items-center gap-1.5 text-[12.5px] text-muted">
                <span class="size-2 rounded-full bg-green-600" aria-hidden="true"></span>
                24-hr recall from the {{ \App\Support\RecallMonitor::connectionName() === 'mysql_live' ? 'live server' : 'local database' }}{{ $recallFetchedAt ? ' · updated ' . $recallFetchedAt->diffForHumans() : '' }}
                <button type="button" wire:click="refreshLive" wire:loading.attr="disabled" wire:target="refreshLive" class="font-bold text-green-800 hover:underline disabled:opacity-60">
                    <span wire:loading.remove wire:target="refreshLive">Refresh</span><span wire:loading wire:target="refreshLive">Refreshing…</span>
                </button>
            </span>
            @endif
        </div>
    </div>

    @if ($overview['recallState'] === 'unreachable')
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
    @endif

    <div class="relative flex flex-col gap-4">
        <div wire:loading.delay class="absolute inset-0 z-[600] rounded-2xl bg-canvas/60"></div>

        <div class="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1.55fr)_minmax(0,1fr)]">
            {{-- Map --}}
            <div class="flex min-w-0 flex-col overflow-hidden rounded-2xl border border-line bg-white">
                <div class="flex flex-wrap items-baseline justify-between gap-2 px-[18px] pt-4 pb-3">
                    <h2 class="text-[17px] font-bold">SWS households{{ $province !== '' ? ' · ' . ucwords(strtolower($province)) : '' }}</h2>
                    <span class="text-[12.5px] text-muted">{{ number_format(count($map['points'])) }} on the map</span>
                </div>
                <div wire:key="ov-map-{{ md5($province . '|' . count($map['points']) . '|' . json_encode($map['counts'])) }}"
                    x-data="overviewMap(@js($map['points']), @js($statuses), @js($canOpenSws ? route('sws') : null))"
                    class="relative isolate z-0 min-h-[440px] flex-1">
                    {{-- Fills the card, so the map is as tall as the column beside it --}}
                    <div x-ref="map" wire:ignore class="absolute inset-0 bg-[#EEF5F0]" role="region" aria-label="Map of SWS households by recall status"></div>
                    <div x-show="loading" class="absolute inset-0 z-[500] flex items-center justify-center bg-white/70 text-sm font-semibold text-green-800">Loading map…</div>
                    @if (!count($map['points']))
                        <div class="pointer-events-none absolute inset-x-0 top-4 z-[500] flex justify-center">
                            <span class="rounded-full bg-white px-4 py-2 text-[13px] font-semibold text-ink-soft shadow">No SWS households with coordinates in this area.</span>
                        </div>
                    @endif
                </div>
                <div class="flex flex-wrap items-center gap-x-4 gap-y-1.5 px-[18px] py-3 text-[12.5px] text-ink-soft">
                    @foreach ($statuses as $key => [$label, $colour])
                        <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-full" style="background: {{ $colour }}"></span>{{ $label }} <strong class="text-ink">{{ number_format($map['counts'][$key]) }}</strong></span>
                    @endforeach
                </div>
            </div>

            <div class="flex min-w-0 flex-col gap-4">
                {{-- Key figures --}}
                <div class="flex flex-col rounded-2xl border border-line bg-white px-5 py-2">
                    @foreach ([
                        ['Household coverage', $pct($hh['coverage'] ?? null), $hh ? number_format($hh['covered']) . ' of ' . number_format($hh['households']) . ' households' : 'no household listing', 'text-ink'],
                        ['24-hr recall · Day 1', $pct($day1['completion'] ?? null), $day1 ? number_format($day1['completed']) . ' of ' . number_format($recall['target']) . ' SWS respondents' : 'no recall data yet', 'text-amber-800'],
                        ['24-hr recall · Day 2', $pct($day2['completion'] ?? null), $day2 ? number_format($day2['completed']) . ' of ' . number_format($recall['target']) . ' SWS respondents' : 'no recall data yet', 'text-red-700'],
                        ['Refusal · Day 1', $pct($day1['refusal'] ?? null), $day1 ? number_format($day1['refused']) . ' refused' : '', 'text-ink'],
                        ['SWS transmitted', number_format($overview['sws']['sent']) . ' / ' . number_format($overview['sws']['total']), $overview['sws']['total'] - $overview['sws']['sent'] ? number_format($overview['sws']['total'] - $overview['sws']['sent']) . ' waiting to be sent' : 'all on the live server', 'text-ink'],
                    ] as [$label, $value, $note, $tone])
                        <div class="flex items-center justify-between gap-3 border-b border-[#EEF3EF] py-3 last:border-b-0">
                            <div class="flex flex-col">
                                <span class="text-[13px] font-semibold text-ink-soft">{{ $label }}</span>
                                <span class="text-[11.5px] text-muted">{{ $note }}</span>
                            </div>
                            <span class="text-[22px] font-extrabold {{ $tone }}">{{ $value }}</span>
                        </div>
                    @endforeach
                </div>

                {{-- Latest activity --}}
                <div class="flex flex-col gap-2 rounded-2xl border border-line bg-white p-5">
                    <h2 class="text-[17px] font-bold">Latest activity</h2>
                    @forelse ($overview['activity'] as $a)
                        <div class="flex flex-col gap-0.5 border-b border-[#EEF3EF] pb-2 text-[12.5px] last:border-b-0 last:pb-0">
                            <span class="flex items-center justify-between gap-2">
                                <strong class="text-ink">{{ $a['what'] }}</strong>
                                <span class="text-[11.5px] text-muted">{{ $a['when']->diffForHumans() }}</span>
                            </span>
                            <span class="truncate text-ink-soft" title="{{ $a['detail'] }}">{{ $a['detail'] }}</span>
                            @if ($a['who'])<span class="text-[11.5px] text-muted">{{ $a['who'] }}</span>@endif
                        </div>
                    @empty
                        <p class="text-sm text-muted">Nothing yet.</p>
                    @endforelse
                </div>
            </div>
        </div>

        {{-- Researchers --}}
        <div class="flex min-w-0 flex-col gap-3 rounded-2xl border border-line bg-white p-[18px]">
            <div class="flex flex-wrap items-baseline justify-between gap-3">
                <h2 class="text-[17px] font-bold">Researchers · 24-hr recall</h2>
                <span class="text-[12.5px] text-muted">completed ÷ recalls each researcher transmitted · details in 24-hr recall monitoring</span>
            </div>
            @php($researchers = collect($recall['researchers'] ?? [])->sortByDesc(fn ($r) => $r[1]['rate'] ?? -1)->values())
            @if ($researchers->isEmpty())
                <p class="text-sm text-muted">No 24-hr recalls yet{{ $province !== '' ? ' in this area' : '' }}.</p>
            @else
                <div class="grid grid-cols-[110px_minmax(0,1fr)_56px_minmax(0,1fr)_56px] items-center gap-x-4 gap-y-2.5 text-[13px]">
                    <span></span><span class="text-[12px] font-bold text-muted">Day 1</span><span></span><span class="text-[12px] font-bold text-muted">Day 2</span><span></span>
                    @foreach ($researchers as $r)
                        <span class="truncate font-bold" title="{{ $r['name'] }}">{{ $r['name'] }}</span>
                        @foreach ([1, 2] as $d)
                            @php($rate = $r[$d]['rate'])
                            <span class="block h-2.5 rounded-full bg-[#EEF3EF]" title="{{ $r[$d]['completed'] }} of {{ $r[$d]['recalls'] }} recalls">
                                <span class="block h-2.5 rounded-full {{ $rate === null ? '' : ($rate >= 80 ? 'bg-green-700' : ($rate >= 50 ? 'bg-amber-500' : 'bg-red-500')) }}" style="width: {{ min(100, $rate ?? 0) }}%"></span>
                            </span>
                            <span class="text-right font-bold">{{ $pct($rate) }}</span>
                        @endforeach
                    @endforeach
                </div>
            @endif
        </div>

        {{-- What people ate --}}
        <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
            <div class="flex flex-col gap-3 rounded-2xl border border-line bg-white p-[22px]">
                <h2 class="text-[17px] font-bold">What people ate · by meal</h2>
                <span class="flex h-3.5 overflow-hidden rounded-md bg-[#EEF5F0]">
                    @foreach ($overview['food']['meals'] as $meal)
                        <span class="block h-3.5" style="width: {{ $meal['pct'] }}%; background: {{ $mealShades[$loop->index] }}" title="{{ $meal['label'] }} {{ $meal['pct'] }}%"></span>
                    @endforeach
                </span>
                <div class="flex flex-wrap gap-x-3.5 gap-y-1.5">
                    @foreach ($overview['food']['meals'] as $meal)
                        <span class="flex items-center gap-1.5 text-[12.5px] text-ink-soft"><span class="size-2.5 rounded-full" style="background: {{ $mealShades[$loop->index] }}"></span>{{ $meal['label'] }} <strong class="text-ink">{{ $meal['pct'] }}%</strong></span>
                    @endforeach
                </div>
                <span class="text-[12px] text-muted">Share of {{ number_format($overview['food']['lines']) }} food lines.</span>
            </div>
            <div class="flex flex-col gap-3 rounded-2xl border border-line bg-white p-[22px]">
                <h2 class="text-[17px] font-bold">Most reported foods</h2>
                @if (count($overview['food']['foods']))
                    <div class="flex flex-wrap gap-2">
                        @foreach ($overview['food']['foods'] as $food)
                            <span class="rounded-full bg-green-100 px-3.5 py-1.5 text-[13px] font-semibold text-green-900">{{ ucfirst(strtolower($food['label'])) }} <span class="font-extrabold">{{ number_format($food['count']) }}</span></span>
                        @endforeach
                    </div>
                @else
                    <p class="text-sm text-muted">No food lines yet{{ $province !== '' ? ' in this area' : '' }}.</p>
                @endif
            </div>
        </div>
    </div>
@endif
