@php
    $filterSelect = 'h-[42px] min-w-[140px] rounded-[10px] border border-field bg-white px-2.5 text-sm font-normal text-ink disabled:bg-canvas';
    $tabs = ['data' => 'All data', 'map' => 'View map', 'entry' => $editingId ? 'Edit record' : 'Add a record', 'upload' => 'Upload file'];
    $who = fn ($change) => $change->user?->name ?? 'Removed account';
    $actionLabel = ['created' => 'Added', 'updated' => 'Edited', 'transmitted' => 'Transmitted'];
    // Columns pinned to the left of the wide table
    $pinned = ['sws_member_no', 'lastname'];
@endphp
<div class="flex flex-col gap-6">
    <x-page-header title="SWS Upload Data" subtitle="SWS transmission records - add or edit one, upload a batch, or read through all of them">
        <a href="{{ route('sws.template') }}" class="flex h-11 items-center gap-2 rounded-[11px] border border-field bg-white px-4 text-sm font-bold text-ink-soft hover:border-green-700">
            <x-icon name="download" size="18" /> SWS template
        </a>
        @if (!$offline)
            {{-- Transmit what isn't on the live server yet, under the current filters --}}
            <button type="button"
                x-on:click="
                    @if ($pendingHere)
                        confirmAlert({
                            icon: 'warning',
                            title: 'Transmit {{ number_format($pendingHere) }} SWS record(s)?',
                            text: @js('These records will be sent to the live server (' . $liveDatabase . '), where the field researchers can access them on their tablets. Records it already has are replaced with these values.' . ($pendingHere < $pendingAll ? ' Only records matching the current filters are sent.' : '')),
                            confirmText: 'Transmit',
                        }).then(ok => ok && busyAlert('Transmitting to the live server…', 'Sending {{ number_format($pendingHere) }} record(s). Keep this page open.', $wire.transmit()))
                    @else
                        notify({ type: 'info', title: 'Nothing to transmit', message: 'Every record {{ $pendingAll ? 'matching these filters ' : '' }}is already on the live server.' })
                    @endif
                "
                class="flex h-11 items-center gap-2 rounded-[11px] bg-green-700 px-4 text-sm font-bold text-white hover:bg-green-800">
                <x-icon name="upload" size="18" /> Transmit to live server
                <span class="rounded-full px-2 py-0.5 text-xs font-extrabold {{ $pendingAll ? 'bg-amber-300 text-amber-950' : 'bg-green-900/40 text-white' }}" title="Records not yet on the live server">{{ number_format($pendingAll) }}</span>
            </button>
        @endif
    </x-page-header>

    @if ($offline)
        <x-survey-offline />
    @else
        <nav class="flex flex-wrap gap-1 rounded-[14px] border border-line bg-white p-1.5" aria-label="SWS sections">
            @foreach ($tabs as $key => $label)
                <button type="button" wire:click="showTab('{{ $key }}')" @if ($tab === $key) aria-current="page" @endif
                    class="flex h-10 items-center gap-1.5 rounded-[10px] px-4 text-sm font-bold transition {{ $tab === $key ? 'bg-green-700 text-white' : 'text-ink-soft hover:bg-[#F6FAF7]' }}">
                    @if ($key === 'map')<x-icon name="map" size="16" />@endif
                    {{ $label }}
                    @if ($key === 'data')<span class="ml-1 text-xs font-semibold opacity-80">{{ number_format($total) }}</span>@endif
                </button>
            @endforeach
        </nav>

        {{-- ===== All data and View map share the filters ===== --}}
        @if (in_array($tab, ['data', 'map'], true))
            <div class="flex min-w-0 flex-col gap-3.5 rounded-2xl border border-line bg-white p-[18px]"
                x-data="{
                    hidden: (() => { try { return JSON.parse(localStorage.getItem('sws.hiddenColumns') || '[]') } catch (e) { return [] } })(),
                    toggle(c) {
                        this.hidden = this.hidden.includes(c) ? this.hidden.filter(x => x !== c) : [...this.hidden, c];
                        try { localStorage.setItem('sws.hiddenColumns', JSON.stringify(this.hidden)) } catch (e) {}
                    },
                    showAll() { this.hidden = []; try { localStorage.removeItem('sws.hiddenColumns') } catch (e) {} },
                }">
                {{-- Hidden columns are hidden by a class on the table, not per cell --}}
                <style>
                    @foreach (['transmitted', ...$columns, 'trail'] as $c).sws-grid.hide-{{ $c }} [data-col="{{ $c }}"] { display: none; }
                    @endforeach
                </style>

                <div class="flex flex-wrap items-end gap-2.5">
                    <label class="flex flex-col gap-1.5 text-[12.5px] font-semibold text-ink-soft">City
                        <select wire:model.live="city" class="{{ $filterSelect }}">
                            <option value="">All cities</option>
                            @foreach ($cities as $option)<option value="{{ $option }}">{{ $option }}</option>@endforeach
                        </select>
                    </label>
                    <label class="flex flex-col gap-1.5 text-[12.5px] font-semibold text-ink-soft">District
                        <select wire:model.live="district" @disabled($city === '') class="{{ $filterSelect }}">
                            <option value="">All</option>
                            @foreach ($districts as $option)<option value="{{ $option }}">{{ $option }}</option>@endforeach
                        </select>
                    </label>
                    <label class="flex flex-col gap-1.5 text-[12.5px] font-semibold text-ink-soft">Barangay
                        <select wire:model.live="barangay" @disabled($city === '') class="{{ $filterSelect }}">
                            <option value="">All</option>
                            @foreach ($barangays as $option)<option value="{{ $option }}">{{ $option }}</option>@endforeach
                        </select>
                    </label>
                    {{-- Hidden for now - see SwsData::SHOW_AUDIT_FILTERS --}}
                    @if (\App\Livewire\SwsData::SHOW_AUDIT_FILTERS)
                    <label class="flex flex-col gap-1.5 text-[12.5px] font-semibold text-ink-soft">Added / edited by
                        <select wire:model.live="changedBy" class="{{ $filterSelect }}">
                            <option value="">Anyone</option>
                            @foreach ($editors as $editor)<option value="{{ $editor->id }}">{{ $editor->name }}</option>@endforeach
                        </select>
                    </label>
                    <label class="flex flex-col gap-1.5 text-[12.5px] font-semibold text-ink-soft">Upload
                        <select wire:model.live="uploadBatch" class="{{ $filterSelect }} max-w-[220px]">
                            <option value="">Any</option>
                            @foreach ($uploads as $batch)<option value="{{ $batch->id }}">{{ $batch->subject }} · {{ $batch->created_at->format('M j, g:i A') }}</option>@endforeach
                        </select>
                    </label>
                    @endif
                    <label class="flex flex-col gap-1.5 text-[12.5px] font-semibold text-ink-soft">Transmitted
                        <select wire:model.live="transmitted" class="{{ $filterSelect }}">
                            <option value="">All</option>
                            <option value="yes">Transmitted</option>
                            <option value="no">Not transmitted</option>
                        </select>
                    </label>
                    <label class="flex min-w-0 flex-[1_1_220px] flex-col gap-1.5 text-[12.5px] font-semibold text-ink-soft">Search
                        <input type="search" wire:model.live.debounce.400ms="search" placeholder="Name, SWS member no., identifier, street, contact, email" class="h-[42px] min-w-0 rounded-[10px] border border-field px-3 text-sm font-normal text-ink outline-none focus:border-green-700">
                    </label>
                    <button type="button" wire:click="clearFilters" class="h-[42px] rounded-[10px] border border-field bg-white px-4 text-sm font-bold text-ink-soft hover:border-green-700">Clear</button>
                </div>

                {{-- While those filters are hidden, still say when one is narrowing the list (e.g. "View records" under an upload) --}}
                @if (!\App\Livewire\SwsData::SHOW_AUDIT_FILTERS && ($changedBy !== '' || $uploadBatch !== ''))
                    <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl bg-blue-50 px-3.5 py-2.5 text-[13px] text-blue-900">
                        <span>
                            Showing only records
                            @if ($uploadBatch !== '') from upload <strong>{{ $uploads->firstWhere('id', (int) $uploadBatch)?->subject ?? '#' . $uploadBatch }}</strong>@endif
                            @if ($changedBy !== '') {{ $uploadBatch !== '' ? 'and' : '' }} added or edited by <strong>{{ $editors->firstWhere('id', (int) $changedBy)?->name ?? 'that account' }}</strong>@endif
                        </span>
                        <button type="button" wire:click="$set('uploadBatch', ''); $set('changedBy', '')" class="font-bold underline">Show all</button>
                    </div>
                @endif

                @if ($tab === 'map')
                    {{-- ===== View map: households from their latitude / longitude ===== --}}
                    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-[#EEF3EF] pt-3 text-[13px]">
                        <span class="text-muted">
                            <strong class="text-ink">{{ number_format(count($map['points'])) }}</strong> of {{ number_format($map['matching']) }} matching household(s) on the map
                            @if ($map['capped']) · showing the first {{ number_format(\App\Livewire\SwsData::MAP_LIMIT) }} - narrow the filters to see the rest @endif
                        </span>
                        <span class="flex flex-wrap items-center gap-2">
                            @if ($map['missing'])
                                <button type="button" wire:click="showMissingCoordinates" class="rounded-full bg-amber-100 px-3 py-1 text-xs font-bold text-amber-900 hover:underline">{{ number_format($map['missing']) }} without coordinates - list them</button>
                            @endif
                            @if ($map['outside'])
                                <span class="rounded-full bg-red-100 px-3 py-1 text-xs font-bold text-red-800" title="Latitude / longitude outside the Philippines - possibly swapped or mistyped">{{ number_format($map['outside']) }} outside the Philippines</span>
                            @endif
                        </span>
                    </div>
                    <div wire:key="sws-map-{{ $map['key'] }}" x-data="swsMap(@js($map['points']), @js($map['focus']))" class="relative isolate z-0 overflow-hidden rounded-xl border border-line">
                        <div x-ref="map" wire:ignore class="h-[68vh] min-h-[420px] w-full bg-[#EEF5F0]" role="region" aria-label="Map of SWS households"></div>
                        <div x-show="loading" class="absolute inset-0 z-[500] flex items-center justify-center bg-white/70 text-sm font-semibold text-green-800">Loading map…</div>
                        @if (!count($map['points']))
                            <div class="pointer-events-none absolute inset-x-0 top-4 z-[500] flex justify-center">
                                <span class="rounded-full bg-white px-4 py-2 text-[13px] font-semibold text-ink-soft shadow">No households with coordinates match these filters.</span>
                            </div>
                        @endif
                    </div>
                    <p class="text-[12px] text-muted">Click a household for its details, to edit it, see its history, or open it in Google Maps. Switch between Street and Satellite at the top right.</p>
                @else
                @if ($coords === 'missing')
                    <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl bg-amber-50 px-3.5 py-2.5 text-[13px] text-amber-900">
                        <span>Showing only records without latitude / longitude. Edit a record to add them.</span>
                        <button type="button" wire:click="$set('coords', '')" class="font-bold underline">Show all records</button>
                    </div>
                @endif

                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-[#EEF3EF] pt-3">
                    <span class="text-[13px] text-muted">
                        @if ($rows->total())
                            Showing <strong class="text-ink">{{ number_format($rows->firstItem()) }}–{{ number_format($rows->lastItem()) }}</strong> of <strong class="text-ink">{{ number_format($rows->total()) }}</strong> record(s)
                        @else
                            No records match
                        @endif
                    </span>
                    <div class="flex flex-wrap items-center gap-2">
                        <label class="flex items-center gap-2 text-[13px] font-semibold text-ink-soft">Rows per page
                            <select wire:model.live="perPage" class="h-9 rounded-[9px] border border-field bg-white px-2 text-sm font-normal text-ink">
                                @foreach ($perPageOptions as $n)<option value="{{ $n }}">{{ $n }}</option>@endforeach
                            </select>
                        </label>
                        <div class="relative" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape.window="open = false">
                            <button type="button" x-on:click="open = !open" x-bind:aria-expanded="open" class="flex h-9 items-center gap-1.5 rounded-[9px] border border-field bg-white px-3 text-[13px] font-bold text-ink-soft hover:border-green-700">
                                <x-icon name="table" size="16" /> Columns <span x-show="hidden.length" x-cloak class="rounded-full bg-amber-100 px-1.5 text-[11px] text-amber-900" x-text="hidden.length + ' hidden'"></span>
                            </button>
                            <div x-show="open" x-cloak x-transition.opacity class="absolute right-0 z-30 mt-1.5 flex max-h-[360px] w-[250px] flex-col gap-0.5 overflow-y-auto rounded-xl border border-line bg-white p-2 shadow-lg">
                                @foreach (['transmitted', ...$columns, 'trail'] as $c)
                                    @continue(in_array($c, $pinned, true))
                                    <label class="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1.5 text-[13px] hover:bg-[#F6FAF7]">
                                        <input type="checkbox" class="accent-green-700" x-bind:checked="!hidden.includes('{{ $c }}')" x-on:change="toggle('{{ $c }}')">
                                        {{ $c === 'trail' ? 'Added / last edited by' : ($c === 'transmitted' ? 'Transmitted' : $labels[$c]) }}
                                    </label>
                                @endforeach
                                <button type="button" x-on:click="showAll()" class="mt-1 rounded-lg px-2 py-1.5 text-left text-[12.5px] font-bold text-green-800 hover:bg-[#F6FAF7]">Show all columns</button>
                            </div>
                        </div>
                        <a href="{{ route('sws.export', $exportQuery) }}" class="flex h-9 items-center gap-1.5 rounded-[9px] border border-field bg-white px-3 text-[13px] font-bold text-ink-soft hover:border-green-700"><x-icon name="download" size="16" /> Export CSV</a>
                    </div>
                </div>

                <div class="relative max-h-[70vh] overflow-auto rounded-xl border border-line">
                    <div wire:loading.delay class="absolute inset-0 z-20 bg-white/60"></div>
                    <table class="sws-grid w-full border-separate border-spacing-0 text-[12.5px]" x-bind:class="hidden.map(c => 'hide-' + c)">
                        <thead class="text-[11.5px] font-bold text-muted">
                            <tr>
                                <th data-col="transmitted" scope="col" class="sticky top-0 z-10 border-b border-line bg-[#F6FAF7] px-3 py-2.5 text-left whitespace-nowrap">
                                    <button type="button" wire:click="sortBy('transmitted')" class="inline-flex items-center gap-1 hover:text-ink">
                                        Transmitted
                                        @if ($sort === 'transmitted')<span aria-hidden="true">{{ $dir === 'asc' ? '▲' : '▼' }}</span>@endif
                                    </button>
                                </th>
                                @foreach ($columns as $c)
                                    @php($sticky = match ($c) { 'sws_member_no' => 'left-0 z-20 w-[140px] min-w-[140px] max-w-[140px]', 'lastname' => 'left-[140px] z-20', default => 'z-10' })
                                    <th data-col="{{ $c }}" scope="col" class="sticky top-0 {{ $sticky }} border-b border-line bg-[#F6FAF7] px-3 py-2.5 text-left whitespace-nowrap {{ $c === 'lastname' ? 'border-r' : '' }}">
                                        @if (in_array($c, $sortable, true))
                                            <button type="button" wire:click="sortBy('{{ $c }}')" class="inline-flex items-center gap-1 hover:text-ink">
                                                {{ $labels[$c] }}
                                                @if ($sort === $c)<span aria-hidden="true">{{ $dir === 'asc' ? '▲' : '▼' }}</span><span class="sr-only">sorted {{ $dir === 'asc' ? 'ascending' : 'descending' }}</span>@endif
                                            </button>
                                        @else
                                            {{ $labels[$c] }}
                                        @endif
                                    </th>
                                @endforeach
                                <th data-col="trail" scope="col" class="sticky top-0 z-10 border-b border-line bg-[#F6FAF7] px-3 py-2.5 text-left whitespace-nowrap">Added / last edited by</th>
                                <th scope="col" class="sticky top-0 right-0 z-20 border-b border-l border-line bg-[#F6FAF7] px-3 py-2.5"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($rows as $row)
                                @php($t = $trail[$row->id] ?? null)
                                <tr wire:key="sws-{{ $row->id }}" class="group">
                                    <td data-col="transmitted" class="border-b border-[#EEF3EF] bg-white px-3 py-2 whitespace-nowrap group-hover:bg-[#F6FAF7]">
                                        @if ($row->transmitted === 'yes')
                                            <span class="rounded-full bg-green-100 px-2.5 py-0.5 text-[11.5px] font-bold text-green-900" title="Sent {{ $row->transmitted_at ? \Illuminate\Support\Carbon::parse($row->transmitted_at)->format('M j, Y g:i A') : '' }}">Yes</span>
                                            @if ($row->transmitted_at)<span class="ml-1 text-[11.5px] text-muted">{{ \Illuminate\Support\Carbon::parse($row->transmitted_at)->format('M j') }}</span>@endif
                                        @else
                                            <span class="rounded-full bg-amber-100 px-2.5 py-0.5 text-[11.5px] font-bold text-amber-900" title="{{ $row->transmitted_at ? 'Changed since it was last sent' : 'Never sent' }}">No</span>
                                            @if ($row->transmitted_at)<span class="ml-1 text-[11.5px] text-muted">changed</span>@endif
                                        @endif
                                    </td>
                                    @foreach ($columns as $c)
                                        @php($sticky = match ($c) { 'sws_member_no' => 'sticky left-0 z-[5] w-[140px] min-w-[140px] max-w-[140px] truncate font-bold', 'lastname' => 'sticky left-[140px] z-[5] border-r font-semibold', default => '' })
                                        <td data-col="{{ $c }}" class="{{ $sticky }} border-b border-[#EEF3EF] bg-white px-3 py-2 whitespace-nowrap group-hover:bg-[#F6FAF7] {{ in_array($c, ['latitude', 'longitude', 'idp_age', 'contact_number'], true) ? 'tabular-nums' : '' }}">
                                            @php($v = $row->{$c})
                                            @if ($v === null || $v === '')
                                                <span class="text-[#B7C4BC]">—</span>
                                            @elseif ($c === 'email_address')
                                                <span class="block max-w-[220px] truncate" title="{{ $v }}">{{ $v }}</span>
                                            @elseif (in_array($c, ['street_name', 'subd', 'indexwomenname'], true))
                                                <span class="block max-w-[200px] truncate" title="{{ $v }}">{{ $v }}</span>
                                            @else
                                                {{ $v }}
                                            @endif
                                        </td>
                                    @endforeach
                                    <td data-col="trail" class="border-b border-[#EEF3EF] bg-white px-3 py-2 text-[12px] whitespace-nowrap group-hover:bg-[#F6FAF7]">
                                        @if (!$t || !$t['added'])
                                            <span class="text-muted">{{ $t ? '' : 'Not recorded' }}</span>
                                        @else
                                            <span class="font-semibold">{{ $who($t['added']) }}</span> <span class="text-muted">· {{ $t['added']->source === 'upload' ? 'upload' : 'form' }} {{ $t['added']->created_at->format('M j, Y') }}</span>
                                        @endif
                                        @if ($t && $t['latest'])
                                            <span class="block"><span class="text-muted">edited by</span> <span class="font-semibold">{{ $who($t['latest']) }}</span> <span class="text-muted">· {{ $t['latest']->created_at->format('M j, Y') }}</span></span>
                                        @endif
                                    </td>
                                    <td class="sticky right-0 z-[5] border-b border-l border-[#EEF3EF] bg-white px-2 py-1.5 whitespace-nowrap group-hover:bg-[#F6FAF7]">
                                        @if ($row->transmitted === 'yes')
                                            {{-- Locked: looks disabled, says why when pressed --}}
                                            <button type="button" aria-disabled="true" title="Cannot edit - already transmitted"
                                                x-on:click="notify({ type: 'warning', title: 'Cannot edit - already transmitted', message: @js($row->sws_member_no . ' was transmitted to the live server' . ($row->transmitted_at ? ' on ' . \Illuminate\Support\Carbon::parse($row->transmitted_at)->format('M j, Y g:i A') : '') . '. This record is already on the live server, so it cannot be edited here anymore.') })"
                                                class="inline-flex size-8 cursor-not-allowed items-center justify-center rounded-lg text-[#C9D4CD]" aria-label="Edit {{ $row->sws_member_no }} (locked - already transmitted)"><x-icon name="lock" size="16" /></button>
                                        @else
                                            <button type="button" wire:click="edit({{ $row->id }})" class="inline-flex size-8 items-center justify-center rounded-lg text-ink-soft hover:bg-green-50 hover:text-green-800" aria-label="Edit {{ $row->sws_member_no }}"><x-icon name="edit" size="16" /></button>
                                        @endif
                                        @if (is_numeric($row->latitude) && is_numeric($row->longitude))
                                            <button type="button" wire:click="showOnMap({{ $row->id }})" class="inline-flex size-8 items-center justify-center rounded-lg text-ink-soft hover:bg-green-50 hover:text-green-800" title="Show on map" aria-label="Show {{ $row->sws_member_no }} on the map"><x-icon name="map-pin" size="16" /></button>
                                        @else
                                            <span class="inline-flex size-8 items-center justify-center text-[#C9D4CD]" title="No coordinates"><x-icon name="map-pin" size="16" /></span>
                                        @endif
                                        <button type="button" wire:click="showHistory({{ $row->id }})" class="inline-flex h-8 items-center rounded-lg px-1.5 text-[12px] font-bold text-ink-soft hover:bg-green-50 hover:text-green-800" aria-label="History of {{ $row->sws_member_no }}">History</button>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="{{ count($columns) + 3 }}" class="py-12 text-muted"><span class="sticky left-0 block px-4">{{ $total ? 'No SWS records match these filters.' : 'No SWS records yet. Add one, or upload the SWS template.' }}</span></td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                {{ $rows->links() }}
                @endif
            </div>
        @endif

        @if (!in_array($tab, ['data', 'map'], true))
            <section class="grid grid-cols-1 items-start gap-4 xl:grid-cols-[minmax(0,1fr)_320px]">
                <div class="flex min-w-0 flex-col gap-4">
                    {{-- ===== Add / edit ===== --}}
                    @if ($tab === 'entry')
                        <form wire:submit="save" class="flex flex-col gap-[18px] rounded-2xl border border-line bg-white p-6" novalidate>
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="flex flex-col gap-1">
                                    <h2 class="text-lg font-bold">{{ $editingId ? 'Edit SWS record' : 'Add an SWS record' }}</h2>
                                    <p class="text-[13.5px] text-muted">Names, area and address are saved in capitals. The same identifiers and SWS member no. can only be used once.</p>
                                </div>
                                @if ($editingId)<span class="rounded-full bg-amber-100 px-3 py-1.5 text-xs font-bold text-amber-900">Editing {{ $record['sws_member_no'] }}</span>@endif
                            </div>

                            <datalist id="sws-cities">@foreach ($allCities as $o)<option value="{{ $o }}">@endforeach</datalist>
                            <datalist id="sws-districts">@foreach ($allDistricts as $o)<option value="{{ $o }}">@endforeach</datalist>
                            <datalist id="sws-barangays">@foreach ($allBarangays as $o)<option value="{{ $o }}">@endforeach</datalist>

                            @foreach ([
                                'AREA' => [['city', 'QUEZON CITY', 'sws-cities'], ['district', 'DISTRICT 1 (optional)', 'sws-districts'], ['barangay', 'BARANGAY 1', 'sws-barangays']],
                                'HOUSEHOLD' => [['identifier1', 'QC001', null], ['identifier2', 'Optional', null], ['identifier3', 'Optional', null]],
                                'RESPONDENT' => [['sws_member_no', 'SWS_001', null], ['lastname', '', null], ['firstname', '', null], ['middlename', 'Optional', null], ['suffix', 'JR (optional)', null], ['idp_age', 'Optional', null], ['indexwomenname', 'Optional', null]],
                                'ADDRESS' => [['floor_no', 'FLOOR 2', null], ['building_no', 'BUILDING 3', null], ['block', 'BLK 81', null], ['street_name', 'MAGINHAWA', null], ['subd', 'Optional', null]],
                                'CONTACT AND LOCATION' => [['contact_number', '09171234567', null], ['email_address', 'name@example.com', null], ['latitude', '14.6427441', null], ['longitude', '121.0607261', null]],
                            ] as $legend => $fields)
                                <fieldset class="grid gap-3 {{ count($fields) === 4 ? 'sm:grid-cols-2' : 'sm:grid-cols-3' }}">
                                    <legend class="pb-2.5 text-xs font-bold tracking-[0.08em] text-green-700">{{ $legend }}</legend>
                                    @foreach ($fields as [$c, $placeholder, $list])
                                        <x-field model="record.{{ $c }}" :label="$labels[$c]" :placeholder="$placeholder" :list="$list"
                                            :inputmode="in_array($c, ['contact_number', 'idp_age'], true) ? 'numeric' : (in_array($c, ['latitude', 'longitude'], true) ? 'decimal' : null)"
                                            :type="$c === 'email_address' ? 'email' : 'text'" />
                                    @endforeach
                                </fieldset>
                            @endforeach

                            <div class="flex justify-end gap-2.5">
                                <button type="button" wire:click="clearRecord" class="h-11 rounded-[11px] border border-field bg-white px-[18px] text-sm font-bold text-ink-soft hover:border-green-700">{{ $editingId ? 'Cancel edit' : 'Clear' }}</button>
                                <button type="submit" wire:loading.attr="disabled" wire:target="save" class="h-11 rounded-[11px] bg-green-700 px-5 text-sm font-bold text-white hover:bg-green-800 disabled:opacity-60">{{ $editingId ? 'Save changes' : 'Save record' }}</button>
                            </div>
                        </form>
                    @endif

                    {{-- ===== Upload ===== --}}
                    @if ($tab === 'upload')
                        @if (!$upload)
                            {{-- Same flow as F11: uploading (%) -> checking -> result, or an error saying why --}}
                            <div class="flex flex-col gap-4 rounded-2xl border border-line bg-white p-6" wire:key="upload-form"
                                x-data="{ state: 'idle', progress: 0, name: '', error: '' }"
                                x-on:livewire-upload-start="state = 'uploading'; progress = 0; error = ''; uploadAlert.start(name)"
                                x-on:livewire-upload-progress="progress = $event.detail.progress; uploadAlert.progress(progress); if (progress >= 100) state = 'checking'"
                                x-on:livewire-upload-finish="state = 'idle'; uploadAlert.done()"
                                x-on:livewire-upload-error="state = 'failed'; error = 'The file could not be uploaded. It may be larger than {{ $maxMb }} MB, or the connection dropped. Try again with a smaller file.'; uploadAlert.failed(error)"
                                x-on:livewire-upload-cancel="state = 'idle'; uploadAlert.done()">
                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <h2 class="text-lg font-bold">Upload SWS data</h2>
                                    <span class="text-[13px] text-muted">CSV or Excel (.xlsx), up to {{ $maxMb }} MB / {{ number_format(\App\Support\SwsStore::MAX_ROWS) }} rows</span>
                                </div>
                                <ol class="flex list-decimal flex-col gap-1 pl-5 text-[13.5px] text-ink-soft">
                                    <li><a href="{{ route('sws.template') }}" class="font-bold text-green-800 underline">Download the SWS template</a>, or use the SWS transmission file - the first row must be the variable names (CITY, DISTRICT … IDP_AGE).</li>
                                    <li>Choose the file. It is checked automatically - nothing is saved yet.</li>
                                    <li>Review the result, then import. A record whose SWS_MEMBER_NO is already here is updated; anything else is added.</li>
                                </ol>

                                <label class="flex cursor-pointer flex-col items-center gap-2.5 rounded-[14px] border-2 border-dashed px-5 py-8 text-center transition"
                                    x-bind:class="state === 'failed' || {{ $errors->has('file') ? 'true' : 'false' }} ? 'border-red-300 bg-red-50' : 'border-[#9FD3B2] bg-[#F6FCF8] hover:bg-green-50'">
                                    <span class="flex size-[52px] items-center justify-center rounded-[14px] bg-green-100 text-green-700"><x-icon name="upload" size="26" /></span>
                                    <strong class="text-[15px]" x-text="name ? name : 'Choose an SWS file'">Choose an SWS file</strong>
                                    <input type="file" wire:model="file" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                                        x-on:change="name = $event.target.files[0]?.name ?? ''"
                                        x-bind:disabled="state === 'uploading' || state === 'checking'"
                                        class="text-[13px] file:mr-3 file:rounded-lg file:border-0 file:bg-green-700 file:px-3 file:py-2 file:font-semibold file:text-white disabled:opacity-60">
                                </label>

                                <div x-show="state === 'uploading'" x-cloak class="flex flex-col gap-1.5" role="status">
                                    <span class="flex justify-between text-[13px] font-semibold text-green-800"><span>Uploading <span x-text="name"></span>…</span><span x-text="progress + '%'"></span></span>
                                    <span class="block h-2 overflow-hidden rounded-full bg-[#EEF5F0]"><span class="block h-2 rounded-full bg-green-700 transition-all" x-bind:style="'width:' + progress + '%'"></span></span>
                                </div>
                                <p x-show="state === 'checking'" x-cloak class="flex items-center gap-2 text-[13px] font-semibold text-green-800" role="status">
                                    <svg class="size-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" class="opacity-25"/><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                                    Uploaded. Checking every row…
                                </p>
                                <p x-show="state === 'failed'" x-cloak class="flex items-start gap-2 rounded-xl bg-red-50 px-3.5 py-3 text-[13px] font-medium text-red-800" role="alert">
                                    <x-icon name="alert" size="18" class="mt-px" /><span x-text="error"></span>
                                </p>
                                @error('file')
                                    <p class="flex items-start gap-2 rounded-xl bg-red-50 px-3.5 py-3 text-[13px] font-medium text-red-800" role="alert" x-init="$root.querySelector('input[type=file]').value = ''">
                                        <x-icon name="alert" size="18" class="mt-px" /><span>{{ $message }} Choose the file again once it is fixed.</span>
                                    </p>
                                @enderror
                            </div>
                        @else
                            <div class="flex flex-col gap-4 rounded-2xl border border-line bg-white p-6" wire:key="upload-result">
                                <div class="flex flex-col gap-1">
                                    <h2 class="text-lg font-bold">Checked: {{ $upload['name'] }}</h2>
                                    <p class="text-[13.5px] text-muted">{{ number_format($upload['rows']) }} row(s) read. Nothing has been saved yet.</p>
                                </div>
                                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                    @foreach ([[$upload['valid'], 'ready to import', 'bg-[#ECFDF3] text-green-900'], [$upload['inserts'], 'new records', 'bg-[#ECFDF3] text-green-900'], [$upload['updates'], 'updates to existing', 'bg-blue-50 text-blue-900'], [$upload['problemCount'], 'problems', $upload['problemCount'] ? 'bg-red-50 text-red-800' : 'bg-[#F6FAF7] text-ink']] as [$value, $label, $tone])
                                        <div class="flex flex-col gap-1 rounded-xl p-4 {{ $tone }}">
                                            <span class="text-2xl font-extrabold">{{ number_format($value) }}</span>
                                            <span class="text-[13px]">{{ $label }}</span>
                                        </div>
                                    @endforeach
                                </div>

                                @if ($upload['problemCount'])
                                    <div class="flex flex-col gap-2">
                                        <p class="text-[13.5px] text-ink-soft">Rows with problems are left out. Fix them in the file and upload it again, or import the rest now.</p>
                                        <div class="max-h-[360px] overflow-auto rounded-xl border border-line">
                                            <table class="w-full border-collapse text-[13px]">
                                                <thead class="sticky top-0 bg-[#F6FAF7] text-xs font-bold text-muted">
                                                    <tr><th class="px-3 py-2.5 text-left">Line</th><th class="px-3 py-2.5 text-left">Column</th><th class="px-3 py-2.5 text-left">Problem</th></tr>
                                                </thead>
                                                <tbody>
                                                    @foreach ($upload['problems'] as $problem)
                                                        <tr class="border-t border-[#EEF3EF]">
                                                            <td class="px-3 py-2 font-bold whitespace-nowrap">{{ $problem['line'] }}</td>
                                                            <td class="px-3 py-2 font-mono text-[12px] whitespace-nowrap">{{ $problem['column'] }}</td>
                                                            <td class="px-3 py-2">{{ $problem['message'] }}</td>
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                        @if ($upload['problemCount'] > count($upload['problems']))
                                            <p class="text-[12.5px] text-muted">Showing the first {{ count($upload['problems']) }} of {{ number_format($upload['problemCount']) }} problems.</p>
                                        @endif
                                    </div>
                                @endif

                                <div class="flex flex-wrap justify-end gap-2.5">
                                    <button type="button" wire:click="discardUpload" class="h-11 rounded-[11px] border border-field bg-white px-[18px] text-sm font-bold text-ink-soft hover:border-green-700">Choose another file</button>
                                    <button type="button" wire:loading.attr="disabled" wire:target="import" @disabled(!$upload['valid'])
                                        x-on:click="confirmAlert({ title: 'Import {{ $upload['valid'] }} SWS record(s)?', text: '{{ $upload['inserts'] }} will be added and {{ $upload['updates'] }} existing record(s) will be updated. Each change is kept in its history.', confirmText: 'Import' }).then(ok => ok && $wire.import())"
                                        class="h-11 rounded-[11px] bg-green-700 px-5 text-sm font-bold text-white hover:bg-green-800 disabled:opacity-60">
                                        Import {{ number_format($upload['valid']) }} record(s)
                                    </button>
                                </div>
                            </div>
                        @endif

                        <div class="flex flex-col gap-3 rounded-2xl border border-line bg-white p-6">
                            <h2 class="text-lg font-bold">Template columns</h2>
                            <div class="overflow-x-auto rounded-xl border border-line">
                                <table class="w-full border-collapse text-[13px]">
                                    <thead class="bg-[#F6FAF7] text-xs font-bold text-muted">
                                        <tr><th class="px-3 py-2.5 text-left">Variable</th><th class="px-3 py-2.5 text-left">What to enter</th></tr>
                                    </thead>
                                    <tbody>
                                        @foreach ([
                                            'CITY, BARANGAY' => 'Required.',
                                            'DISTRICT' => 'Optional.',
                                            'IDENTIFIER1' => 'Required. IDENTIFIER2 and IDENTIFIER3 are optional.',
                                            'SWS_MEMBER_NO' => 'Required - letters, numbers and - _ / . (e.g. SWS_001). Together with IDENTIFIER1-3 it identifies the record: the same identifiers and member no. update that record; the same member no. in another household is a separate record.',
                                            'LASTNAME, FIRSTNAME' => 'Required. MIDDLENAME and SUFFIX are optional. Saved in capitals.',
                                            'FLOOR_NO, BUILDING_NO, BLOCK, STREET_NAME, SUBD' => 'Optional address parts.',
                                            'CONTACT_NUMBER' => 'Optional. 7 to 11 digits; 9171234567 (leading 0 dropped by Excel) is saved as 09171234567.',
                                            'EMAIL_ADDRESS' => 'Optional. A valid email address.',
                                            'LATITUDE, LONGITUDE' => 'Optional. Decimal degrees, e.g. 14.6427441 and 121.0607261.',
                                            'INDEXWOMENNAME' => 'Optional. Name of the index woman.',
                                            'IDP_AGE' => 'Optional. Whole years, 0 to 120.',
                                        ] as $column => $help)
                                            <tr class="border-t border-[#EEF3EF]">
                                                <td class="px-3 py-2 font-mono text-[12.5px] font-semibold">{{ $column }}</td>
                                                <td class="px-3 py-2 text-ink-soft">{{ $help }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endif
                </div>

                {{-- Activity --}}
                <aside class="flex flex-col gap-3 rounded-2xl border border-line bg-white p-5">
                    <h2 class="text-[17px] font-bold">Recent SWS activity</h2>
                    @forelse ($activities as $activity)
                        <div class="flex flex-col gap-0.5 rounded-xl bg-[#F6FAF7] px-3 py-2.5 text-[13px]">
                            <span class="flex items-center justify-between gap-2">
                                <span class="font-bold">{{ ['imported' => 'Uploaded', 'created' => 'Added', 'updated' => 'Edited', 'transmitted' => 'Transmitted'][$activity->action] ?? ucfirst($activity->action) }}</span>
                                <span class="text-xs text-muted">{{ $activity->created_at->diffForHumans() }}</span>
                            </span>
                            <span class="truncate text-ink-soft" title="{{ $activity->subject }}">{{ $activity->subject }}</span>
                            @if ($activity->action === 'transmitted')
                                <span class="text-xs text-muted">{{ $activity->inserted }} new · {{ $activity->updated }} updated on the live server</span>
                            @elseif ($activity->action === 'imported')
                                <span class="flex items-center justify-between gap-2 text-xs text-muted">
                                    {{ $activity->inserted }} new · {{ $activity->updated }} updated
                                    <button type="button" wire:click="showUpload({{ $activity->id }})" class="font-bold text-green-800 hover:underline">View records</button>
                                </span>
                            @elseif ($activity->sws_id)
                                <button type="button" wire:click="showHistory({{ $activity->sws_id }})" class="self-start text-xs font-bold text-green-800 hover:underline">History</button>
                            @endif
                            <span class="text-xs text-muted">{{ $activity->user?->name ?? 'Removed account' }}{{ $activity->area ? ' · ' . $activity->area : '' }}</span>
                        </div>
                    @empty
                        <div class="flex flex-col items-center gap-2 rounded-xl bg-[#F6FAF7] px-4 py-8 text-center">
                            <x-icon name="database" size="26" class="text-green-700" />
                            <span class="text-sm font-semibold">Nothing yet</span>
                            <span class="text-[13px] text-muted">Added records and uploads will be listed here.</span>
                        </div>
                    @endforelse
                </aside>
            </section>
        @endif

        {{-- Record history --}}
        @if ($history !== null)
            <div class="fixed inset-0 z-40 flex justify-end bg-forest-950/40" wire:click.self="closeHistory" x-data x-on:keydown.escape.window="$wire.closeHistory()">
                <div class="flex h-full w-full max-w-[520px] flex-col gap-4 overflow-y-auto bg-white p-6 shadow-xl" role="dialog" aria-modal="true" aria-labelledby="sws-history-title">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex flex-col gap-1">
                            <h2 id="sws-history-title" class="text-lg font-bold">Record history</h2>
                            <p class="text-[13px] text-muted">{{ $history->first()?->member ?? 'SWS record #' . $historyId }}</p>
                        </div>
                        <button type="button" wire:click="closeHistory" class="flex size-10 items-center justify-center rounded-lg text-ink-soft hover:bg-[#F6FAF7]" aria-label="Close history"><x-icon name="x" /></button>
                    </div>
                    @forelse ($history as $change)
                        <div wire:key="sws-change-{{ $change->id }}" class="flex flex-col gap-2 rounded-xl border border-line p-3.5 text-[13px]">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <span>
                                    <span class="rounded-full px-2.5 py-0.5 text-xs font-bold {{ ['created' => 'bg-green-100 text-green-900', 'transmitted' => 'bg-forest-900 text-white'][$change->action] ?? 'bg-blue-100 text-blue-900' }}">{{ $actionLabel[$change->action] ?? $change->action }}</span>
                                    <span class="ml-1 font-bold">{{ $who($change) }}</span>
                                </span>
                                <span class="text-xs text-muted">{{ $change->created_at->format('M j, Y g:i A') }}</span>
                            </div>
                            <span class="text-xs text-muted">
                                @if ($change->action === 'transmitted')
                                    {{ ucfirst($change->changes['live'][1] ?? 'sent to the live server') }}
                                @else
                                    via {{ $change->source === 'upload' ? 'upload' . ($change->upload ? ' ' . $change->upload->subject : '') : 'form' }}
                                @endif
                            </span>
                            @if ($change->action === 'updated' && $change->changes)
                                <table class="w-full border-collapse text-[12.5px]">
                                    <thead class="text-left text-[11.5px] text-muted"><tr><th class="py-1 pr-2">Field</th><th class="py-1 pr-2">Before</th><th class="py-1">After</th></tr></thead>
                                    <tbody>
                                        @foreach ($change->changes as $field => [$old, $new])
                                            <tr class="border-t border-[#EEF3EF]">
                                                <td class="py-1.5 pr-2 font-semibold">{{ $labels[$field] ?? $field }}</td>
                                                <td class="py-1.5 pr-2 text-red-700 line-through decoration-red-300">{{ $old === null || $old === '' ? '(blank)' : $old }}</td>
                                                <td class="py-1.5 text-green-800">{{ $new === null || $new === '' ? '(blank)' : $new }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            @endif
                        </div>
                    @empty
                        <div class="rounded-xl bg-[#F6FAF7] px-4 py-8 text-center text-[13px] text-muted">No changes recorded for this record.</div>
                    @endforelse
                </div>
            </div>
        @endif
    @endif
</div>
