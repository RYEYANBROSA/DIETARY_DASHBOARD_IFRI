@php
    $statusColors = ['1' => '#15803D', '2' => '#F59E0B', '3' => '#DC2626', '4' => '#B91C1C', '5' => '#2563EB', '6' => '#D97706', '7' => '#64748B', '8' => '#94A3B8', '' => '#CBD5E1'];
@endphp
<div class="flex flex-col gap-6">
    <x-page-header title="Dashboard" subtitle="Survey coverage and food recall at a glance">
        @if ($canOpenF72)
            <a href="{{ route('f72') }}" class="flex h-11 items-center gap-2 rounded-[11px] bg-green-700 px-4 text-sm font-bold text-white hover:bg-green-800"><x-icon name="table" size="18" /> Coverage by barangay</a>
        @endif
    </x-page-header>

    @if (!$stats)
        <x-survey-offline />
    @else
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div class="flex flex-col gap-2 rounded-2xl border border-line bg-white p-5">
                <span class="text-[13px] font-semibold text-muted">Households listed</span>
                <span class="text-[32px] leading-none font-extrabold">{{ number_format($stats['households']) }}</span>
                <span class="text-[13px] text-muted">across {{ number_format($stats['barangays']) }} barangays · {{ number_format($stats['local_areas']) }} local areas</span>
            </div>
            <div class="flex flex-col gap-2 rounded-2xl bg-forest-900 p-5 text-white">
                <span class="text-[13px] font-semibold text-mint-200">Coverage</span>
                <span class="text-[32px] leading-none font-extrabold">{{ $stats['coverage'] }}%</span>
                <span class="block h-2 rounded-full bg-forest-700" role="progressbar" aria-valuenow="{{ $stats['coverage'] }}" aria-valuemin="0" aria-valuemax="100" aria-label="Household coverage">
                    <span class="block h-2 rounded-full bg-mint-300" style="width: {{ min(100, $stats['coverage']) }}%"></span>
                </span>
                <span class="text-[13px] text-mint-100">{{ number_format($stats['covered']) }} covered · {{ number_format($stats['not_covered']) }} not covered</span>
            </div>
            <div class="flex flex-col gap-2 rounded-2xl border border-line bg-white p-5">
                <span class="text-[13px] font-semibold text-muted">F11 members</span>
                <span class="text-[32px] leading-none font-extrabold">{{ number_format($stats['members']) }}</span>
                <span class="text-[13px] text-muted">{{ number_format($stats['recalled_members']) }} with a food recall</span>
            </div>
            <div class="flex flex-col gap-2 rounded-2xl border border-line bg-white p-5">
                <span class="text-[13px] font-semibold text-muted">F72 food lines</span>
                <span class="text-[32px] leading-none font-extrabold">{{ number_format($stats['food_lines']) }}</span>
                <span class="text-[13px] text-muted">from {{ number_format($stats['researchers']) }} field researchers</span>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
            {{-- Interview status --}}
            <div class="flex flex-col gap-4 rounded-2xl border border-line bg-white p-[22px]">
                <div class="flex items-baseline justify-between gap-3">
                    <h2 class="text-[17px] font-bold">Household interview status</h2>
                    <span class="text-[12.5px] text-muted">{{ number_format($stats['no_status']) }} without a status</span>
                </div>
                <span class="flex h-4 overflow-hidden rounded-md bg-[#EEF5F0]">
                    @foreach ($stats['statuses'] as $status)
                        @if ($status['count'])<span class="block h-4" style="width: {{ $status['pct'] }}%; background: {{ $statusColors[$status['code']] }}" title="{{ $status['label'] }} {{ $status['pct'] }}%"></span>@endif
                    @endforeach
                </span>
                <div class="grid grid-cols-1 gap-x-6 gap-y-2 sm:grid-cols-2">
                    @foreach ($stats['statuses'] as $status)
                        <div class="flex items-center gap-2 text-[13.5px]">
                            <span class="size-2.5 shrink-0 rounded-full" style="background: {{ $statusColors[$status['code']] }}"></span>
                            <span class="flex-1 text-ink-soft">{{ $status['label'] }}</span>
                            <span class="font-bold">{{ number_format($status['count']) }}</span>
                            <span class="w-12 text-right text-xs text-muted">{{ $status['pct'] }}%</span>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Meals --}}
            <div class="flex flex-col gap-4 rounded-2xl border border-line bg-white p-[22px]">
                <div class="flex items-baseline justify-between gap-3">
                    <h2 class="text-[17px] font-bold">Food lines by meal</h2>
                    <span class="text-[12.5px] text-muted">share of {{ number_format($stats['food_lines']) }}</span>
                </div>
                @foreach ($stats['meals'] as $meal)
                    <div class="grid grid-cols-[110px_minmax(0,1fr)_52px] items-center gap-3 text-[13.5px]">
                        <span class="font-semibold text-ink-soft">{{ $meal['label'] }}</span>
                        <span class="block h-3 rounded-md bg-[#EEF5F0]"><span class="block h-3 rounded-md bg-green-700" style="width: {{ $meal['width'] }}%"></span></span>
                        <span class="text-right font-bold">{{ $meal['pct'] }}%</span>
                    </div>
                @endforeach
            </div>

            {{-- Municipalities --}}
            <div class="flex flex-col gap-4 rounded-2xl border border-line bg-white p-[22px]">
                <div class="flex items-baseline justify-between gap-3">
                    <h2 class="text-[17px] font-bold">Households by municipality</h2>
                    <span class="text-[12.5px] text-muted">top {{ count($stats['municipalities']) }}</span>
                </div>
                @php($maxMunicipality = max(1, collect($stats['municipalities'])->max('count')))
                @foreach ($stats['municipalities'] as $row)
                    <div class="grid grid-cols-[140px_minmax(0,1fr)_52px] items-center gap-3 text-[13.5px]">
                        <span class="truncate font-semibold text-ink-soft" title="{{ $row['label'] }}">{{ $row['label'] }}</span>
                        <span class="block h-3 rounded-md bg-[#EEF5F0]"><span class="block h-3 rounded-md bg-green-500" style="width: {{ round($row['count'] / $maxMunicipality * 100, 1) }}%"></span></span>
                        <span class="text-right font-bold">{{ number_format($row['count']) }}</span>
                    </div>
                @endforeach
            </div>

            {{-- Weighing method --}}
            <div class="flex flex-col gap-4 rounded-2xl border border-line bg-white p-[22px]">
                <div class="flex items-baseline justify-between gap-3">
                    <h2 class="text-[17px] font-bold">Weighing method</h2>
                    <span class="text-[12.5px] text-muted">share of food lines with a method</span>
                </div>
                @foreach ($stats['weight_methods'] as $method)
                    <div class="grid grid-cols-[120px_minmax(0,1fr)_52px] items-center gap-3 text-[13.5px]">
                        <span class="font-semibold text-ink-soft">{{ $method['label'] }}</span>
                        <span class="block h-3 rounded-md bg-[#EEF5F0]"><span class="block h-3 rounded-md bg-forest-700" style="width: {{ $method['pct'] }}%"></span></span>
                        <span class="text-right font-bold">{{ $method['pct'] }}%</span>
                    </div>
                @endforeach
            </div>

            {{-- Foods --}}
            <div class="flex flex-col gap-3.5 rounded-2xl border border-line bg-white p-[22px]">
                <h2 class="text-[17px] font-bold">Most reported foods</h2>
                @forelse ($stats['foods'] as $food)
                    <div class="flex items-center gap-3.5 rounded-xl bg-[#F6FAF7] px-3 py-2.5">
                        <span class="flex size-[30px] items-center justify-center rounded-[9px] bg-green-100 text-[13px] font-extrabold text-green-900">{{ $loop->iteration }}</span>
                        <span class="flex-1 text-sm font-bold">{{ $food['label'] }}</span>
                        <span class="text-[13.5px] font-semibold text-ink-soft">{{ number_format($food['count']) }} lines</span>
                    </div>
                @empty
                    <p class="text-sm text-muted">No food lines yet.</p>
                @endforelse
            </div>

            {{-- At a glance --}}
            <div class="flex flex-col gap-3.5 rounded-2xl border border-line bg-white p-[22px]">
                <h2 class="text-[17px] font-bold">Data at a glance</h2>
                <div class="grid grid-cols-2 gap-3">
                    @foreach (array_filter([
                        [$stats['local_areas'], 'local areas'],
                        [$stats['researchers'], 'field researchers'],
                        $stats['transmissions'] !== null ? [$stats['transmissions'], 'transmissions'] : null,
                        $stats['daily_researchers'] !== null ? [$stats['daily_researchers'], 'researchers transmitting per day (avg)'] : null,
                    ]) as [$value, $label])
                        <div class="flex flex-col gap-1 rounded-xl bg-[#ECFDF3] p-4">
                            <span class="text-2xl font-extrabold text-green-900">{{ is_float($value) ? $value : number_format($value) }}</span>
                            <span class="text-[13px] text-ink-soft">{{ $label }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Researcher performance --}}
        @if ($researchers)
            <div class="flex min-w-0 flex-col gap-3.5 rounded-2xl border border-line bg-white p-[18px]">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-[17px] font-bold">Researcher performance</h2>
                    <input type="search" wire:model.live.debounce.400ms="researcherSearch" placeholder="Search researcher" aria-label="Search researcher"
                        class="h-[42px] w-full min-w-0 rounded-[10px] border border-field px-3 text-sm text-ink outline-none focus:border-green-700 sm:w-[260px]">
                </div>
                <div class="relative overflow-x-auto rounded-xl border border-line">
                    <div wire:loading.delay class="absolute inset-0 z-10 bg-white/60"></div>
                    <table class="w-full border-collapse text-[13.5px]">
                        <thead class="bg-[#F6FAF7] text-xs font-bold text-muted">
                            <tr>
                                <th class="px-3.5 py-3 text-left whitespace-nowrap">Researcher</th>
                                <th class="px-3.5 py-3 text-right whitespace-nowrap">Day 1 lines</th>
                                <th class="px-3.5 py-3 text-right whitespace-nowrap">Day 2 lines</th>
                                <th class="px-3.5 py-3 text-right whitespace-nowrap">Total</th>
                                <th class="px-3.5 py-3 text-left whitespace-nowrap">Day 1 / Day 2 split</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($researchers as $row)
                                @php($d1 = $row->total > 0 ? round($row->day1 / $row->total * 100, 1) : 0)
                                @php($fullName = trim($row->fn . ' ' . $row->ln))
                                <tr wire:key="r-{{ md5($row->researcher) }}" class="border-t border-[#EEF3EF]">
                                    <td class="px-3.5 py-3 whitespace-nowrap"><span class="font-bold">{{ $fullName ?: $row->researcher }}</span>@if ($fullName)<span class="block text-xs text-muted">{{ $row->researcher }}</span>@endif</td>
                                    <td class="px-3.5 py-3 text-right">{{ number_format($row->day1) }}</td>
                                    <td class="px-3.5 py-3 text-right">{{ number_format($row->day2) }}</td>
                                    <td class="px-3.5 py-3 text-right font-bold">{{ number_format($row->total) }}</td>
                                    <td class="px-3.5 py-3">
                                        <span class="flex items-center gap-2">
                                            <span class="flex h-2 w-32 overflow-hidden rounded-full bg-green-300"><span class="block h-2 bg-green-700" style="width: {{ $d1 }}%"></span></span>
                                            <span class="text-xs text-muted">{{ $d1 }}% / {{ round(100 - $d1, 1) }}%</span>
                                        </span>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-4 py-10 text-center text-muted">No researchers match.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                {{ $researchers->links() }}
            </div>
        @endif
    @endif
</div>
