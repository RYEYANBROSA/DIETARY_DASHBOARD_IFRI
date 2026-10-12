@php
    $select = 'h-11 w-full min-w-0 rounded-[10px] border bg-white px-2.5 text-sm text-ink outline-none focus:border-green-700 disabled:bg-canvas';
    $filterSelect = 'h-[42px] min-w-[150px] rounded-[10px] border border-field bg-white px-2.5 text-sm font-normal text-ink disabled:bg-canvas';
    $tabs = ['records' => 'Encoded members', 'encode' => $editingId ? 'Edit member' : 'Encode a member', 'upload' => 'Upload CSV'];
    $household = fn ($row) => collect([$row->identifier1, $row->identifier2, $row->identifier3])->filter(fn ($v) => $v !== null && $v !== '')->implode('-');
    $who = fn ($change) => $change->user?->name ?? 'Removed account';
    $how = fn ($change) => $change->source === 'upload' ? 'upload' . ($change->upload ? ' ' . $change->upload->subject : '') : 'form';
    $actionLabel = ['created' => 'Added', 'updated' => 'Edited'];
@endphp
<div class="flex flex-col gap-6">
    <x-page-header title="F11 Encoding & Upload" subtitle="Encode household members one at a time, or upload a batch from the CSV template">
        <a href="{{ route('f11.template') }}" class="flex h-11 items-center gap-2 rounded-[11px] border border-field bg-white px-4 text-sm font-bold text-ink-soft hover:border-green-700">
            <x-icon name="download" size="18" /> CSV template
        </a>
    </x-page-header>

    @if ($offline)
        <x-survey-offline />
    @else
        <nav class="flex flex-wrap gap-1 rounded-[14px] border border-line bg-white p-1.5" aria-label="F11 sections">
            @foreach ($tabs as $key => $label)
                <button type="button" wire:click="showTab('{{ $key }}')" @if ($tab === $key) aria-current="page" @endif
                    class="h-10 rounded-[10px] px-4 text-sm font-bold transition {{ $tab === $key ? 'bg-green-700 text-white' : 'text-ink-soft hover:bg-[#F6FAF7]' }}">
                    {{ $label }}
                    @if ($key === 'records')<span class="ml-1 text-xs font-semibold opacity-80">{{ number_format($totalMembers) }}</span>@endif
                </button>
            @endforeach
        </nav>

        <section class="grid grid-cols-1 items-start gap-4 xl:grid-cols-[minmax(0,1fr)_320px]">
            <div class="flex min-w-0 flex-col gap-4">
                {{-- ===== Records ===== --}}
                @if ($tab === 'records')
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
                                    @foreach ($filterMunicipalities as $option)<option value="{{ $option }}">{{ $option }}</option>@endforeach
                                </select>
                            </label>
                            <label class="flex flex-col gap-1.5 text-[12.5px] font-semibold text-ink-soft">Barangay
                                <select wire:model.live="barangay" @disabled($municipality === '') class="{{ $filterSelect }}">
                                    <option value="">All</option>
                                    @foreach ($filterBarangays as $option)<option value="{{ $option }}">{{ $option }}</option>@endforeach
                                </select>
                            </label>
                            <label class="flex flex-col gap-1.5 text-[12.5px] font-semibold text-ink-soft">Added / edited by
                                <select wire:model.live="changedBy" class="{{ $filterSelect }}">
                                    <option value="">Anyone</option>
                                    @foreach ($editors as $editor)<option value="{{ $editor->id }}">{{ $editor->name }}</option>@endforeach
                                </select>
                            </label>
                            <label class="flex flex-col gap-1.5 text-[12.5px] font-semibold text-ink-soft">Upload
                                <select wire:model.live="uploadBatch" class="{{ $filterSelect }} max-w-[240px]">
                                    <option value="">Any</option>
                                    @foreach ($uploads as $batch)<option value="{{ $batch->id }}">{{ $batch->subject }} · {{ $batch->created_at->format('M j, g:i A') }}</option>@endforeach
                                </select>
                            </label>
                            <label class="flex min-w-0 flex-[1_1_200px] flex-col gap-1.5 text-[12.5px] font-semibold text-ink-soft">Search
                                <input type="search" wire:model.live.debounce.400ms="search" placeholder="Name, household, SWS member no." class="h-[42px] min-w-0 rounded-[10px] border border-field px-3 text-sm font-normal text-ink outline-none focus:border-green-700">
                            </label>
                            <button type="button" wire:click="clearFilters" class="h-[42px] rounded-[10px] border border-field bg-white px-4 text-sm font-bold text-ink-soft hover:border-green-700">Clear</button>
                        </div>

                        <div class="relative overflow-x-auto rounded-xl border border-line">
                            <div wire:loading.delay class="absolute inset-0 z-10 bg-white/60"></div>
                            <table class="w-full border-collapse text-[13.5px]">
                                <thead class="bg-[#F6FAF7] text-xs font-bold text-muted">
                                    <tr>
                                        <th class="px-3.5 py-3 text-left whitespace-nowrap">Area</th>
                                        <th class="px-3.5 py-3 text-left whitespace-nowrap">Household</th>
                                        <th class="px-3.5 py-3 text-left whitespace-nowrap">No.</th>
                                        <th class="px-3.5 py-3 text-left whitespace-nowrap">Name</th>
                                        <th class="px-3.5 py-3 text-left whitespace-nowrap">Category</th>
                                        <th class="px-3.5 py-3 text-left whitespace-nowrap">Age</th>
                                        <th class="px-3.5 py-3 text-left whitespace-nowrap">Day</th>
                                        <th class="px-3.5 py-3 text-left whitespace-nowrap">Status</th>
                                        <th class="px-3.5 py-3 text-left whitespace-nowrap">Added / last edited by</th>
                                        <th class="px-3.5 py-3 text-right whitespace-nowrap"><span class="sr-only">Actions</span></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($records as $row)
                                        <tr wire:key="f11-{{ $row->id }}" class="border-t border-[#EEF3EF] {{ $editingId === $row->id ? 'bg-green-50' : '' }}">
                                            <td class="px-3.5 py-3 whitespace-nowrap">{{ $row->municipality }} · {{ $row->barangay }}<span class="block text-xs text-muted">{{ $row->province }}</span></td>
                                            <td class="px-3.5 py-3 font-semibold whitespace-nowrap">{{ $household($row) }}</td>
                                            <td class="px-3.5 py-3 whitespace-nowrap">{{ $row->member_no }}</td>
                                            <td class="px-3.5 py-3 font-bold whitespace-nowrap">{{ $row->surname }}, {{ $row->givenname }}<span class="block text-xs font-normal text-muted">{{ $row->sex }} · born {{ $row->birthdate }}</span></td>
                                            <td class="px-3.5 py-3 whitespace-nowrap">{{ $row->member_category }}</td>
                                            <td class="px-3.5 py-3 whitespace-nowrap">{{ (int) $row->age_years > 0 ? $row->age_years . ' yr' : (int) $row->age_months . ' mo' }}</td>
                                            <td class="px-3.5 py-3 whitespace-nowrap"><span class="rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-bold text-green-900">Day {{ $row->foodrecall_day }}</span></td>
                                            <td class="px-3.5 py-3 whitespace-nowrap">{{ $statuses[(string) $row->interview_status] ?? ($row->interview_status ?: '—') }}</td>
                                            <td class="px-3.5 py-3 text-[12.5px] whitespace-nowrap">
                                                @php($t = $trail[$row->id] ?? null)
                                                @if (!$t)
                                                    <span class="text-muted">Field tablet</span>
                                                @else
                                                    @if ($t['added'])
                                                        <span class="block"><span class="font-semibold">{{ $who($t['added']) }}</span> <span class="text-muted">· {{ $t['added']->source === 'upload' ? 'upload' : 'encoded' }} {{ $t['added']->created_at->format('M j, Y') }}</span></span>
                                                    @else
                                                        <span class="block text-muted">Field tablet</span>
                                                    @endif
                                                    @if ($t['latest'])
                                                        <span class="block"><span class="text-muted">edited by</span> <span class="font-semibold">{{ $who($t['latest']) }}</span> <span class="text-muted">· {{ $t['latest']->source === 'upload' ? 'upload' : 'form' }} {{ $t['latest']->created_at->format('M j, Y') }}</span></span>
                                                    @endif
                                                @endif
                                            </td>
                                            <td class="px-3.5 py-3 text-right whitespace-nowrap">
                                                <button type="button" wire:click="showHistory({{ $row->id }})" class="inline-flex h-9 items-center rounded-lg px-2 text-[12.5px] font-bold text-ink-soft hover:bg-green-50 hover:text-green-800" aria-label="History of {{ $row->surname }}, {{ $row->givenname }}">History</button>
                                                <button type="button" wire:click="edit({{ $row->id }})" class="inline-flex size-9 items-center justify-center rounded-lg text-ink-soft hover:bg-green-50 hover:text-green-800" aria-label="Edit {{ $row->surname }}, {{ $row->givenname }}"><x-icon name="edit" size="18" /></button>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="10" class="px-4 py-10 text-center text-muted">No F11 members match these filters.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                        {{ $records->links() }}
                    </div>
                @endif

                {{-- ===== Encode / edit ===== --}}
                @if ($tab === 'encode')
                    <form wire:submit="save" class="flex flex-col gap-[18px] rounded-2xl border border-line bg-white p-6" novalidate>
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="flex flex-col gap-1">
                                <h2 class="text-lg font-bold">{{ $editingId ? 'Edit member' : 'Encode a household member' }}</h2>
                                <p class="text-[13.5px] text-muted">Saved straight to F11. Age is worked out from the birthdate and the reference date.</p>
                            </div>
                            @if ($editingId)
                                <span class="rounded-full bg-amber-100 px-3 py-1.5 text-xs font-bold text-amber-900">Editing record #{{ $editingId }}</span>
                            @endif
                        </div>

                        <fieldset class="grid gap-3 sm:grid-cols-3">
                            <legend class="pb-2.5 text-xs font-bold tracking-[0.08em] text-green-700">AREA</legend>
                            @foreach (['province' => [$provinces, 'Province', false], 'municipality' => [$municipalities, 'Municipality', $member['province'] === ''], 'barangay' => [$barangays, 'Barangay', $member['municipality'] === '']] as $field => [$options, $label, $disabled])
                                <div class="flex min-w-0 flex-col gap-1.5">
                                    <label for="member_{{ $field }}" class="text-[13px] font-semibold text-ink-soft">{{ $label }}</label>
                                    <select id="member_{{ $field }}" wire:model.live="member.{{ $field }}" @disabled($disabled) class="{{ $select }} {{ $errors->has('member.' . $field) ? 'border-red-600' : 'border-field' }}">
                                        <option value="">Select</option>
                                        @foreach ($options as $option)<option value="{{ $option }}">{{ $option }}</option>@endforeach
                                        {{-- Keep a stored value selectable even if it is no longer in the area list --}}
                                        @if ($member[$field] !== '' && !$options->contains($member[$field]))<option value="{{ $member[$field] }}">{{ $member[$field] }}</option>@endif
                                    </select>
                                    @error('member.' . $field) <p class="text-[12.5px] font-medium text-red-700">{{ $message }}</p> @enderror
                                </div>
                            @endforeach
                        </fieldset>

                        <fieldset class="grid gap-3 sm:grid-cols-3">
                            <legend class="pb-2.5 text-xs font-bold tracking-[0.08em] text-green-700">HOUSEHOLD</legend>
                            @if ($households->isNotEmpty())
                                <div class="flex min-w-0 flex-col gap-1.5 sm:col-span-3">
                                    <label for="member_household" class="text-[13px] font-semibold text-ink-soft">Listed household</label>
                                    <select id="member_household" wire:model.live="household" class="{{ $select }} border-field">
                                        <option value="">Pick from the household listing, or type the identifiers below</option>
                                        @foreach ($households as $hh)
                                            <option value="{{ $hh->identifier1 }}|{{ $hh->identifier2 }}|{{ $hh->identifier3 }}">{{ $household($hh) }} — {{ $hh->household_head ?: 'no household head' }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endif
                            <x-field model="member.identifier1" label="Identifier 1" placeholder="SUN-0005" />
                            <x-field model="member.identifier2" label="Identifier 2" placeholder="6722" />
                            <x-field model="member.identifier3" label="Identifier 3" placeholder="Optional" />
                        </fieldset>

                        <fieldset class="grid gap-3 sm:grid-cols-3">
                            <legend class="pb-2.5 text-xs font-bold tracking-[0.08em] text-green-700">MEMBER</legend>
                            <x-field model="member.member_no" label="Member no." placeholder="01" inputmode="numeric" />
                            <x-field model="member.sws_member_no" label="SWS member no." placeholder="6722-2 (optional)" />
                            <div class="flex min-w-0 flex-col gap-1.5">
                                <label for="member_member_category" class="text-[13px] font-semibold text-ink-soft">Member category</label>
                                <select id="member_member_category" wire:model="member.member_category" class="{{ $select }} {{ $errors->has('member.member_category') ? 'border-red-600' : 'border-field' }}">
                                    <option value="">Select</option>
                                    @foreach ($categories as $option)<option value="{{ $option }}">{{ $option }}</option>@endforeach
                                </select>
                                @error('member.member_category') <p class="text-[12.5px] font-medium text-red-700">{{ $message }}</p> @enderror
                            </div>
                            <x-field model="member.surname" label="Surname" />
                            <x-field model="member.givenname" label="Given name" />
                            <x-field model="member.birthdate" label="Birthdate" type="date" />
                            <div class="flex min-w-0 flex-col gap-1.5">
                                <label for="member_sex" class="text-[13px] font-semibold text-ink-soft">Sex</label>
                                <select id="member_sex" wire:model="member.sex" class="{{ $select }} {{ $errors->has('member.sex') ? 'border-red-600' : 'border-field' }}">
                                    <option value="">Select</option>
                                    <option value="FEMALE">Female</option>
                                    <option value="MALE">Male</option>
                                </select>
                                @error('member.sex') <p class="text-[12.5px] font-medium text-red-700">{{ $message }}</p> @enderror
                            </div>
                            <div class="flex min-w-0 flex-col gap-1.5">
                                <label for="member_psc" class="text-[13px] font-semibold text-ink-soft">PSC</label>
                                <select id="member_psc" wire:model="member.psc" class="{{ $select }} {{ $errors->has('member.psc') ? 'border-red-600' : 'border-field' }}">
                                    <option value="">None</option>
                                    @foreach ($pscOptions as $option)<option value="{{ $option }}">{{ $option }}</option>@endforeach
                                </select>
                                @error('member.psc') <p class="text-[12.5px] font-medium text-red-700">{{ $message }}</p> @enderror
                            </div>
                            <x-field model="member.csc" label="CSC" placeholder="Optional" />
                        </fieldset>

                        <fieldset class="grid gap-3 sm:grid-cols-3">
                            <legend class="pb-2.5 text-xs font-bold tracking-[0.08em] text-green-700">INTERVIEW</legend>
                            <div class="flex min-w-0 flex-col gap-1.5">
                                <label for="member_day" class="text-[13px] font-semibold text-ink-soft">Recall day</label>
                                <select id="member_day" wire:model="member.foodrecall_day" class="{{ $select }} {{ $errors->has('member.foodrecall_day') ? 'border-red-600' : 'border-field' }}">
                                    <option value="1">Day 1</option>
                                    <option value="2">Day 2</option>
                                </select>
                                @error('member.foodrecall_day') <p class="text-[12.5px] font-medium text-red-700">{{ $message }}</p> @enderror
                            </div>
                            <x-field model="member.reference_date" label="Reference date" type="date" />
                            <div class="flex min-w-0 flex-col gap-1.5">
                                <label for="member_status" class="text-[13px] font-semibold text-ink-soft">Interview status</label>
                                <select id="member_status" wire:model="member.interview_status" class="{{ $select }} {{ $errors->has('member.interview_status') ? 'border-red-600' : 'border-field' }}">
                                    @foreach ($statuses as $code => $label)<option value="{{ $code }}">{{ $code }} — {{ $label }}</option>@endforeach
                                </select>
                                @error('member.interview_status') <p class="text-[12.5px] font-medium text-red-700">{{ $message }}</p> @enderror
                            </div>
                            <div class="sm:col-span-3 grid gap-3 sm:grid-cols-2">
                                <x-field model="member.interview_status_others" label="Interview status — others" placeholder="Optional" />
                                <x-field model="member.refuse_remarks" label="Refusal remarks" placeholder="Optional" />
                            </div>
                        </fieldset>

                        <div class="flex justify-end gap-2.5">
                            <button type="button" wire:click="clearMember" class="h-11 rounded-[11px] border border-field bg-white px-[18px] text-sm font-bold text-ink-soft hover:border-green-700">{{ $editingId ? 'Cancel edit' : 'Clear' }}</button>
                            <button type="submit" wire:loading.attr="disabled" wire:target="save" class="h-11 rounded-[11px] bg-green-700 px-5 text-sm font-bold text-white hover:bg-green-800 disabled:opacity-60">{{ $editingId ? 'Save changes' : 'Save member' }}</button>
                        </div>
                    </form>
                @endif

                {{-- ===== Upload ===== --}}
                @if ($tab === 'upload')
                    @if (!$upload)
                        @php($maxMb = round(\App\Support\F11Store::maxUploadKb() / 1024, 1))
                        {{--
                            Upload states, shown as they happen: choosing -> uploading (with %)
                            -> checking rows -> result panel, or an error saying why. Livewire
                            runs the check (updatedFile) before it fires upload-finish, so
                            "checking" spans 100% -> finish, and finish always frees the input.
                        --}}
                        <div class="flex flex-col gap-4 rounded-2xl border border-line bg-white p-6"
                            x-data="{ state: 'idle', progress: 0, name: '', error: '' }"
                            x-on:livewire-upload-start="state = 'uploading'; progress = 0; error = ''"
                            x-on:livewire-upload-progress="progress = $event.detail.progress; if (progress >= 100) state = 'checking'"
                            x-on:livewire-upload-finish="state = 'idle'"
                            x-on:livewire-upload-error="state = 'failed'; error = 'The file could not be uploaded. It may be larger than {{ $maxMb }} MB, or the connection dropped. Try again with a smaller file.'"
                            x-on:livewire-upload-cancel="state = 'idle'">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <h2 class="text-lg font-bold">Upload a batch</h2>
                                <span class="text-[13px] text-muted">CSV from the template, up to {{ $maxMb }} MB / {{ number_format(\App\Support\F11Store::MAX_ROWS) }} rows</span>
                            </div>
                            <ol class="flex list-decimal flex-col gap-1 pl-5 text-[13.5px] text-ink-soft">
                                <li><a href="{{ route('f11.template') }}" class="font-bold text-green-800 underline">Download the CSV template</a> and fill in one member per row, keeping the first row as it is.</li>
                                <li>Save it as CSV and choose it below. It is checked automatically - nothing is saved yet.</li>
                                <li>Review the result, then import. A member already in F11 (same area, identifiers and member no.) is updated; anyone else is added.</li>
                            </ol>

                            <label class="flex cursor-pointer flex-col items-center gap-2.5 rounded-[14px] border-2 border-dashed px-5 py-8 text-center transition"
                                x-bind:class="state === 'failed' || {{ $errors->has('file') ? 'true' : 'false' }} ? 'border-red-300 bg-red-50' : 'border-[#9FD3B2] bg-[#F6FCF8] hover:bg-green-50'">
                                <span class="flex size-[52px] items-center justify-center rounded-[14px] bg-green-100 text-green-700"><x-icon name="upload" size="26" /></span>
                                <strong class="text-[15px]" x-text="name ? name : 'Choose an F11 CSV file'">Choose an F11 CSV file</strong>
                                <input type="file" wire:model="file" accept=".csv,text/csv"
                                    x-on:change="name = $event.target.files[0]?.name ?? ''"
                                    x-bind:disabled="state === 'uploading' || state === 'checking'"
                                    class="text-[13px] file:mr-3 file:rounded-lg file:border-0 file:bg-green-700 file:px-3 file:py-2 file:font-semibold file:text-white disabled:opacity-60">
                            </label>

                            {{-- Live status --}}
                            <div x-show="state === 'uploading'" x-cloak class="flex flex-col gap-1.5" role="status">
                                <span class="flex justify-between text-[13px] font-semibold text-green-800"><span>Uploading <span x-text="name"></span>…</span><span x-text="progress + '%'"></span></span>
                                <span class="block h-2 overflow-hidden rounded-full bg-[#EEF5F0]"><span class="block h-2 rounded-full bg-green-700 transition-all" x-bind:style="'width:' + progress + '%'"></span></span>
                            </div>
                            <p x-show="state === 'checking'" x-cloak class="flex items-center gap-2 text-[13px] font-semibold text-green-800" role="status">
                                <svg class="size-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" class="opacity-25"/><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                                Uploaded. Checking every row against the template…
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
                        <div class="flex flex-col gap-4 rounded-2xl border border-line bg-white p-6">
                            <div class="flex flex-col gap-1">
                                <h2 class="text-lg font-bold">Checked: {{ $upload['name'] }}</h2>
                                <p class="text-[13.5px] text-muted">{{ number_format($upload['rows']) }} row(s) read. Nothing has been saved yet.</p>
                            </div>
                            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                @foreach ([[$upload['valid'], 'ready to import', 'bg-[#ECFDF3] text-green-900'], [$upload['inserts'], 'new members', 'bg-[#ECFDF3] text-green-900'], [$upload['updates'], 'updates to existing', 'bg-blue-50 text-blue-900'], [$upload['problemCount'], 'problems', $upload['problemCount'] ? 'bg-red-50 text-red-800' : 'bg-[#F6FAF7] text-ink']] as [$value, $label, $tone])
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
                                                        <td class="px-3 py-2 whitespace-nowrap">{{ $problem['column'] }}</td>
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
                                <button type="button" wire:click="import" wire:loading.attr="disabled" wire:target="import" @disabled(!$upload['valid'])
                                    wire:confirm="Import {{ $upload['valid'] }} member(s) into F11? {{ $upload['updates'] }} existing record(s) will be overwritten."
                                    class="h-11 rounded-[11px] bg-green-700 px-5 text-sm font-bold text-white hover:bg-green-800 disabled:opacity-60">
                                    Import {{ number_format($upload['valid']) }} member(s)
                                </button>
                            </div>
                        </div>
                    @endif

                    <div class="flex flex-col gap-3 rounded-2xl border border-line bg-white p-6">
                        <h2 class="text-lg font-bold">Template columns</h2>
                        <div class="overflow-x-auto rounded-xl border border-line">
                            <table class="w-full border-collapse text-[13px]">
                                <thead class="bg-[#F6FAF7] text-xs font-bold text-muted">
                                    <tr><th class="px-3 py-2.5 text-left">Column</th><th class="px-3 py-2.5 text-left">What to enter</th></tr>
                                </thead>
                                <tbody>
                                    @foreach ([
                                        'province, municipality, barangay' => 'Required. Spelled exactly as in the local area list.',
                                        'identifier1, identifier2' => 'Required. The household identifiers from the household listing.',
                                        'identifier3' => 'Optional - leave blank if the household has none.',
                                        'sws_member_no' => 'Optional, e.g. 6722-2.',
                                        'member_category' => 'Required: ' . implode(' / ', $categories) . '.',
                                        'member_no' => 'Required. Up to 3 digits; 1 is saved as 01.',
                                        'surname, givenname' => 'Required. Saved in capitals.',
                                        'birthdate' => 'Required. YYYY-MM-DD or M/D/YYYY (e.g. 8/27/1990).',
                                        'sex' => 'Required: MALE or FEMALE (M / F also accepted).',
                                        'csc' => 'Optional.',
                                        'psc' => 'Optional: ' . implode(' / ', $pscOptions) . '.',
                                        'foodrecall_day' => 'Required: 1 or 2.',
                                        'reference_date' => 'Required. YYYY-MM-DD or MM-DD-YYYY (e.g. 05-01-2026). Age is counted up to this date.',
                                        'interview_status' => 'Required. A code: ' . collect($statuses)->map(fn ($l, $c) => "{$c} {$l}")->implode(', ') . '.',
                                        'interview_status_others, refuse_remarks' => 'Optional notes.',
                                    ] as $column => $help)
                                        <tr class="border-t border-[#EEF3EF]">
                                            <td class="px-3 py-2 font-mono text-[12.5px] font-semibold whitespace-nowrap">{{ $column }}</td>
                                            <td class="px-3 py-2 text-ink-soft">{{ $help }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif
            </div>

            {{-- Activity trail --}}
            <aside class="flex flex-col gap-3 rounded-2xl border border-line bg-white p-5">
                <h2 class="text-[17px] font-bold">Recent F11 activity</h2>
                @forelse ($activities as $activity)
                    <div class="flex flex-col gap-0.5 rounded-xl bg-[#F6FAF7] px-3 py-2.5 text-[13px]">
                        <span class="flex items-center justify-between gap-2">
                            <span class="font-bold">
                                {{ ['imported' => 'Uploaded', 'created' => 'Encoded', 'updated' => 'Edited'][$activity->action] ?? ucfirst($activity->action) }}
                            </span>
                            <span class="text-xs text-muted">{{ $activity->created_at->diffForHumans() }}</span>
                        </span>
                        <span class="truncate text-ink-soft" title="{{ $activity->subject }}">{{ $activity->subject }}</span>
                        @if ($activity->action === 'imported')
                            <span class="flex items-center justify-between gap-2 text-xs text-muted">
                                {{ $activity->inserted }} new · {{ $activity->updated }} updated
                                <button type="button" wire:click="showUpload({{ $activity->id }})" class="font-bold text-green-800 hover:underline">View members</button>
                            </span>
                        @elseif ($activity->f11_id)
                            <button type="button" wire:click="showHistory({{ $activity->f11_id }})" class="self-start text-xs font-bold text-green-800 hover:underline">History</button>
                        @endif
                        <span class="text-xs text-muted">{{ $activity->user?->name ?? 'Removed account' }}{{ $activity->area ? ' · ' . $activity->area : '' }}</span>
                    </div>
                @empty
                    <div class="flex flex-col items-center gap-2 rounded-xl bg-[#F6FAF7] px-4 py-8 text-center">
                        <x-icon name="table" size="26" class="text-green-700" />
                        <span class="text-sm font-semibold">Nothing yet</span>
                        <span class="text-[13px] text-muted">Encoded members and uploads will be listed here.</span>
                    </div>
                @endforelse
            </aside>
        </section>

        {{-- Member history --}}
        @if ($history !== null)
            <div class="fixed inset-0 z-40 flex justify-end bg-forest-950/40" wire:click.self="closeHistory" x-data x-on:keydown.escape.window="$wire.closeHistory()">
                <div class="flex h-full w-full max-w-[520px] flex-col gap-4 overflow-y-auto bg-white p-6 shadow-xl" role="dialog" aria-modal="true" aria-labelledby="history-title">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex flex-col gap-1">
                            <h2 id="history-title" class="text-lg font-bold">Member history</h2>
                            <p class="text-[13px] text-muted">{{ $history->first()?->member ?? 'F11 record #' . $historyId }}</p>
                        </div>
                        <button type="button" wire:click="closeHistory" class="flex size-10 items-center justify-center rounded-lg text-ink-soft hover:bg-[#F6FAF7]" aria-label="Close history"><x-icon name="x" /></button>
                    </div>

                    @forelse ($history as $change)
                        <div wire:key="change-{{ $change->id }}" class="flex flex-col gap-2 rounded-xl border border-line p-3.5 text-[13px]">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <span>
                                    <span class="rounded-full px-2.5 py-0.5 text-xs font-bold {{ $change->action === 'created' ? 'bg-green-100 text-green-900' : 'bg-blue-100 text-blue-900' }}">{{ $actionLabel[$change->action] ?? $change->action }}</span>
                                    <span class="ml-1 font-bold">{{ $who($change) }}</span>
                                </span>
                                <span class="text-xs text-muted">{{ $change->created_at->format('M j, Y g:i A') }}</span>
                            </div>
                            <span class="text-xs text-muted">via {{ $how($change) }}</span>
                            @if ($change->action === 'updated' && $change->changes)
                                <table class="w-full border-collapse text-[12.5px]">
                                    <thead class="text-left text-[11.5px] text-muted"><tr><th class="py-1 pr-2">Field</th><th class="py-1 pr-2">Before</th><th class="py-1">After</th></tr></thead>
                                    <tbody>
                                        @foreach ($change->changes as $field => [$old, $new])
                                            <tr class="border-t border-[#EEF3EF]">
                                                <td class="py-1.5 pr-2 font-semibold">{{ ucfirst($labels[$field] ?? str_replace('_', ' ', $field)) }}</td>
                                                <td class="py-1.5 pr-2 text-red-700 line-through decoration-red-300">{{ $old === null || $old === '' ? '(blank)' : $old }}</td>
                                                <td class="py-1.5 text-green-800">{{ $new === null || $new === '' ? '(blank)' : $new }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            @endif
                        </div>
                    @empty
                        <div class="rounded-xl bg-[#F6FAF7] px-4 py-8 text-center text-[13px] text-muted">No changes recorded. This member came from the field tablets and hasn't been edited here.</div>
                    @endforelse
                </div>
            </div>
        @endif
    @endif
</div>
