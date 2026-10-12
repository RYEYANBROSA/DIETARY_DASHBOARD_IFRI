@php
    $filterSelect = 'h-[42px] min-w-[150px] rounded-[10px] border border-field bg-white px-2.5 text-sm font-normal text-ink disabled:bg-canvas';
    $tabs = ['areas' => 'Coverage by barangay', 'households' => 'Households', 'members' => 'Members recalled', 'lines' => 'Food lines'];
    $th = 'px-3.5 py-3 text-left whitespace-nowrap';
    $td = 'px-3.5 py-3 whitespace-nowrap';
    $hhId = fn ($row) => collect([$row->identifier1, $row->identifier2, $row->identifier3])->filter(fn ($v) => $v !== null && $v !== '')->implode('-');
    $statusTone = fn ($code) => match ((string) $code) {
        '1' => 'bg-green-100 text-green-900',
        '2', '6' => 'bg-amber-100 text-amber-900',
        '3', '4' => 'bg-red-100 text-red-800',
        '5' => 'bg-blue-100 text-blue-900',
        default => 'bg-[#EEF3EF] text-ink-soft',
    };
@endphp
<div class="flex flex-col gap-6">
    <x-page-header title="F72 Food Recall" subtitle="Coverage, households and food recall lines from the survey, by area" />

    @if ($offline)
        <x-survey-offline />
    @else
        {{-- Filters + downloads --}}
        <div class="flex min-w-0 flex-col gap-3.5 rounded-2xl border border-line bg-white p-[18px]">
            <div class="flex flex-wrap items-end gap-2.5">
                <label class="flex flex-col gap-1.5 text-[12.5px] font-semibold text-ink-soft">Province
                    <select wire:model.live="province" class="{{ $filterSelect }}">
                        <option value="">All provinces</option>
                        @foreach ($provinces as $option)<option value="{{ $option }}">{{ $option }}</option>@endforeach
                    </select>
                </label>
                <label class="flex flex-col gap-1.5 text-[12.5px] font-semibold text-ink-soft">Municipality
                    <select wire:model.live="municipality" @disabled($province === '') class="{{ $filterSelect }}">
                        <option value="">All</option>
                        @foreach ($municipalities as $option)<option value="{{ $option }}">{{ $option }}</option>@endforeach
                    </select>
                </label>
                <label class="flex flex-col gap-1.5 text-[12.5px] font-semibold text-ink-soft">Barangay
                    <select wire:model.live="barangay" @disabled($municipality === '') class="{{ $filterSelect }}">
                        <option value="">All</option>
                        @foreach ($barangays as $option)<option value="{{ $option }}">{{ $option }}</option>@endforeach
                    </select>
                </label>
                <label class="flex flex-col gap-1.5 text-[12.5px] font-semibold text-ink-soft">Recall day
                    <select wire:model.live="day" class="h-[42px] min-w-[110px] rounded-[10px] border border-field bg-white px-2.5 text-sm font-normal text-ink">
                        <option value="">Both</option>
                        <option value="1">Day 1</option>
                        <option value="2">Day 2</option>
                    </select>
                </label>
                <button type="button" wire:click="clearFilters" class="h-[42px] rounded-[10px] border border-field bg-white px-4 text-sm font-bold text-ink-soft hover:border-green-700">Clear</button>
            </div>
            <div class="flex flex-wrap items-center gap-2 border-t border-[#EEF3EF] pt-3.5">
                <span class="mr-1 text-[12.5px] font-semibold text-muted">Download {{ $filter ? implode(' / ', $filter) : 'all areas' }}:</span>
                <a href="{{ route('f72.export.merged', $filter) }}" class="flex h-9 items-center gap-1.5 rounded-[9px] border border-field px-3 text-[13px] font-bold text-ink-soft hover:border-green-700"><x-icon name="download" size="16" /> Merged F72 CSV</a>
                <a href="{{ route('f72.export.data', $filter) }}" class="flex h-9 items-center gap-1.5 rounded-[9px] border border-field px-3 text-[13px] font-bold text-ink-soft hover:border-green-700"><x-icon name="download" size="16" /> All tables (ZIP)</a>
                @if ($province !== '')
                    <a href="{{ route('f72.export.households', $filter) }}" class="flex h-9 items-center gap-1.5 rounded-[9px] border border-field px-3 text-[13px] font-bold text-ink-soft hover:border-green-700"><x-icon name="download" size="16" /> Per barangay & household (ZIP)</a>
                @else
                    <span class="text-[12.5px] text-muted">Pick a province for the per-household ZIP.</span>
                @endif
            </div>
        </div>

        {{-- Figures for the current filter --}}
        @php($mealShades = ['#14532D', '#4ADE80', '#15803D', '#86EFAC', '#166534', '#BBF7D0', '#22C55E'])
        <div class="grid grid-cols-2 gap-3 xl:grid-cols-5">
            <div class="flex flex-col gap-1 rounded-[14px] border border-line bg-white px-[18px] py-4">
                <span class="text-[12.5px] font-semibold text-muted">Households</span>
                <span class="text-2xl font-extrabold">{{ number_format($households['households']) }}</span>
                <span class="text-xs text-muted">{{ number_format($households['no_status']) }} without a status</span>
            </div>
            <div class="flex flex-col gap-1 rounded-[14px] bg-forest-900 px-[18px] py-4 text-white">
                <span class="text-[12.5px] font-semibold text-mint-200">Covered</span>
                <span class="text-2xl font-extrabold">{{ $households['coverage'] }}%</span>
                <span class="text-xs text-mint-100">{{ number_format($households['covered']) }} covered · {{ number_format($households['not_covered']) }} not</span>
            </div>
            <div class="flex flex-col gap-1 rounded-[14px] border border-line bg-white px-[18px] py-4">
                <span class="text-[12.5px] font-semibold text-muted">F11 members</span>
                <span class="text-2xl font-extrabold">{{ number_format($members) }}</span>
                <span class="text-xs text-muted">{{ number_format($recalled) }} member-day recalls</span>
            </div>
            <div class="flex flex-col gap-1 rounded-[14px] border border-line bg-white px-[18px] py-4">
                <span class="text-[12.5px] font-semibold text-muted">Food lines</span>
                <span class="text-2xl font-extrabold">{{ number_format($totalLines) }}</span>
                <span class="truncate text-xs text-muted">Top: {{ $topFood ? $topFood['label'] . ' (' . number_format($topFood['count']) . ')' : '—' }}</span>
            </div>
            <div class="col-span-2 flex flex-col gap-2 rounded-[14px] border border-line bg-white px-[18px] py-4 xl:col-span-1">
                <span class="text-[12.5px] font-semibold text-muted">Meal mix</span>
                <span class="flex h-3.5 overflow-hidden rounded-md bg-[#EEF5F0]">
                    @foreach ($meals as $meal)
                        <span class="block h-3.5" style="width: {{ $meal['pct'] }}%; background: {{ $mealShades[$loop->index] }}" title="{{ $meal['label'] }} {{ $meal['pct'] }}%"></span>
                    @endforeach
                </span>
                <div class="flex flex-wrap gap-x-3 gap-y-1">
                    @foreach ($meals as $meal)
                        <span class="flex items-center gap-1.5 text-[11.5px] text-ink-soft"><span class="size-2 rounded-full" style="background: {{ $mealShades[$loop->index] }}"></span>{{ $meal['label'] }} {{ $meal['pct'] }}%</span>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="flex flex-wrap gap-2">
            @foreach ($households['statuses'] as $status)
                <span class="rounded-full px-3 py-1 text-xs font-bold {{ $statusTone($status['code']) }}">{{ $status['label'] }} · {{ number_format($status['count']) }}</span>
            @endforeach
        </div>

        {{-- Tables --}}
        <div class="flex min-w-0 flex-col gap-3.5 rounded-2xl border border-line bg-white p-[18px]">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <nav class="flex flex-wrap gap-1 rounded-xl bg-[#F6FAF7] p-1" aria-label="F72 views">
                    @foreach ($tabs as $key => $label)
                        <button type="button" wire:click="showTab('{{ $key }}')" @if ($tab === $key) aria-current="page" @endif
                            class="h-9 rounded-[9px] px-3.5 text-[13.5px] font-bold transition {{ $tab === $key ? 'bg-white text-green-800 shadow-sm' : 'text-ink-soft hover:text-ink' }}">{{ $label }}</button>
                    @endforeach
                </nav>
                <input type="search" wire:model.live.debounce.400ms="search" aria-label="Search this table"
                    placeholder="{{ match ($tab) { 'areas' => 'Search area', 'households' => 'Head, address, mobile, identifier', 'members' => 'Name, household, researcher', default => 'Food item, household, member, researcher' } }}"
                    class="h-[42px] w-full min-w-0 rounded-[10px] border border-field px-3 text-sm text-ink outline-none focus:border-green-700 sm:w-[300px]">
            </div>

            <div class="relative overflow-x-auto rounded-xl border border-line">
                <div wire:loading.delay class="absolute inset-0 z-10 bg-white/60"></div>
                <table class="w-full border-collapse text-[13.5px]">
                    @if ($tab === 'areas')
                        <thead class="bg-[#F6FAF7] text-xs font-bold text-muted">
                            <tr>
                                <th class="{{ $th }}">Barangay</th>
                                <th class="{{ $th }} text-right">Households</th>
                                <th class="{{ $th }} text-right">Covered</th>
                                <th class="{{ $th }}">Coverage</th>
                                <th class="{{ $th }} text-right">Not complete</th>
                                <th class="{{ $th }} text-right">Refused</th>
                                <th class="{{ $th }} text-right">Not at home</th>
                                <th class="{{ $th }} text-right">No status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($rows as $row)
                                @php($pct = $row->households > 0 ? round($row->covered / $row->households * 100, 1) : 0)
                                <tr wire:key="area-{{ $loop->index }}-{{ md5($row->province . $row->municipality . $row->barangay) }}" class="border-t border-[#EEF3EF]">
                                    <td class="{{ $td }}">
                                        <button type="button" wire:click="openArea(@js((string) $row->province), @js((string) $row->municipality), @js((string) $row->barangay))" class="text-left font-bold text-green-800 hover:underline">{{ $row->barangay ?: '(no barangay)' }}</button>
                                        <span class="block text-xs text-muted">{{ $row->municipality }}, {{ $row->province }}</span>
                                    </td>
                                    <td class="{{ $td }} text-right font-semibold">{{ number_format($row->households) }}</td>
                                    <td class="{{ $td }} text-right">{{ number_format($row->covered) }}</td>
                                    <td class="{{ $td }}">
                                        <span class="flex items-center gap-2">
                                            <span class="block h-2 w-24 rounded-full bg-[#EEF5F0]"><span class="block h-2 rounded-full {{ $pct >= 80 ? 'bg-green-700' : ($pct >= 50 ? 'bg-amber-500' : 'bg-red-600') }}" style="width: {{ min(100, $pct) }}%"></span></span>
                                            <span class="text-xs font-bold">{{ $pct }}%</span>
                                        </span>
                                    </td>
                                    <td class="{{ $td }} text-right">{{ number_format($row->incomplete) }}</td>
                                    <td class="{{ $td }} text-right">{{ number_format($row->refused) }}</td>
                                    <td class="{{ $td }} text-right">{{ number_format($row->not_home) }}</td>
                                    <td class="{{ $td }} text-right">{{ number_format($row->no_status) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="8" class="px-4 py-10 text-center text-muted">No listed households match these filters.</td></tr>
                            @endforelse
                        </tbody>
                    @elseif ($tab === 'households')
                        <thead class="bg-[#F6FAF7] text-xs font-bold text-muted">
                            <tr>
                                <th class="{{ $th }}">Household</th>
                                <th class="{{ $th }}">Head / address</th>
                                <th class="{{ $th }}">Area</th>
                                <th class="{{ $th }} text-right">Day 1</th>
                                <th class="{{ $th }} text-right">Day 2</th>
                                <th class="{{ $th }}">Mobile</th>
                                <th class="{{ $th }}">Status</th>
                                <th class="{{ $th }} text-right"><span class="sr-only">Download</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($rows as $row)
                                <tr wire:key="hh-{{ $row->id }}" class="border-t border-[#EEF3EF]">
                                    <td class="{{ $td }} font-bold">{{ $hhId($row) }}</td>
                                    <td class="px-3.5 py-3"><span class="font-semibold">{{ $row->household_head ?: '—' }}</span><span class="block max-w-[260px] truncate text-xs text-muted" title="{{ $row->address }}">{{ $row->address }}</span></td>
                                    <td class="{{ $td }}">{{ $row->barangay }}<span class="block text-xs text-muted">{{ $row->municipality }}</span></td>
                                    <td class="{{ $td }} text-right font-semibold">{{ $row->day1 }}</td>
                                    <td class="{{ $td }} text-right font-semibold">{{ $row->day2 }}</td>
                                    <td class="{{ $td }}">{{ $row->mobile_no ?: '—' }}</td>
                                    <td class="{{ $td }}"><span class="rounded-full px-2.5 py-0.5 text-xs font-bold {{ $statusTone($row->interview_status) }}">{{ $statusNames[trim((string) $row->interview_status)] ?? 'No status' }}</span></td>
                                    <td class="{{ $td }} text-right">
                                        <a href="{{ route('f72.export.data', ['province' => $row->province, 'municipality' => $row->municipality, 'barangay' => $row->barangay, 'identifier1' => $row->identifier1, 'identifier2' => $row->identifier2, 'identifier3' => $row->identifier3, 'head' => $row->household_head]) }}"
                                            class="inline-flex size-9 items-center justify-center rounded-lg text-ink-soft hover:bg-green-50 hover:text-green-800" title="Download this household's data (ZIP)" aria-label="Download data for household {{ $hhId($row) }}"><x-icon name="download" size="18" /></a>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="8" class="px-4 py-10 text-center text-muted">No listed households match these filters.</td></tr>
                            @endforelse
                        </tbody>
                    @elseif ($tab === 'members')
                        <thead class="bg-[#F6FAF7] text-xs font-bold text-muted">
                            <tr>
                                <th class="{{ $th }}">Member</th>
                                <th class="{{ $th }}">Household</th>
                                <th class="{{ $th }}">Area</th>
                                <th class="{{ $th }}">Day</th>
                                <th class="{{ $th }} text-right">Food lines</th>
                                <th class="{{ $th }}">Researcher</th>
                                <th class="{{ $th }}">Recorded</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($rows as $row)
                                <tr wire:key="mem-{{ $loop->index }}-{{ md5(json_encode($row)) }}" class="border-t border-[#EEF3EF]">
                                    <td class="{{ $td }}">
                                        <span class="font-bold">{{ $row->surname ? $row->surname . ', ' . $row->givenname : 'Not in F11' }}</span>
                                        <span class="block text-xs text-muted">No. {{ $row->member_no }}{{ $row->member_category ? ' · ' . $row->member_category : '' }}</span>
                                    </td>
                                    <td class="{{ $td }} font-semibold">{{ $hhId($row) }}</td>
                                    <td class="{{ $td }}">{{ $row->barangay }}<span class="block text-xs text-muted">{{ $row->municipality }}</span></td>
                                    <td class="{{ $td }}"><span class="rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-bold text-green-900">Day {{ $row->foodrecall_day }}</span></td>
                                    <td class="{{ $td }} text-right font-semibold">{{ number_format($row->line_count) }}</td>
                                    <td class="{{ $td }}">{{ $row->researcher ?: '—' }}</td>
                                    <td class="{{ $td }}">{{ $row->recorded_at ? \Illuminate\Support\Carbon::parse($row->recorded_at)->format('M j, Y g:i A') : '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="px-4 py-10 text-center text-muted">No recalled members match these filters.</td></tr>
                            @endforelse
                        </tbody>
                    @else
                        <thead class="bg-[#F6FAF7] text-xs font-bold text-muted">
                            <tr>
                                <th class="{{ $th }}">Area</th>
                                <th class="{{ $th }}">Household</th>
                                <th class="{{ $th }}">Member</th>
                                <th class="{{ $th }}">Day</th>
                                <th class="{{ $th }}">Meal</th>
                                <th class="{{ $th }}">Food item</th>
                                <th class="{{ $th }} text-right">Weight (g)</th>
                                <th class="{{ $th }}">Method</th>
                                <th class="{{ $th }}">Researcher</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($rows as $row)
                                <tr wire:key="f72-{{ $row->id }}" class="border-t border-[#EEF3EF]">
                                    <td class="{{ $td }}">{{ $row->municipality }} · {{ $row->barangay }}<span class="block text-xs text-muted">{{ $row->province }}</span></td>
                                    <td class="{{ $td }} font-semibold">{{ $hhId($row) }}</td>
                                    <td class="{{ $td }}">{{ $row->member_no }}</td>
                                    <td class="{{ $td }}"><span class="rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-bold text-green-900">Day {{ $row->foodrecall_day }}</span></td>
                                    <td class="{{ $td }}">{{ $mealNames[(int) $row->mealcode] ?? '—' }}</td>
                                    <td class="{{ $td }} font-bold">{{ $row->fooditem ?: '—' }}</td>
                                    <td class="{{ $td }} text-right">{{ $row->foodweight }}</td>
                                    <td class="{{ $td }}">{{ $weightNames[(string) $row->weight_method] ?? '—' }}</td>
                                    <td class="{{ $td }}">{{ $row->researcher }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="9" class="px-4 py-10 text-center text-muted">No food lines match these filters.</td></tr>
                            @endforelse
                        </tbody>
                    @endif
                </table>
            </div>
            {{ $rows->links() }}
        </div>
    @endif
</div>
