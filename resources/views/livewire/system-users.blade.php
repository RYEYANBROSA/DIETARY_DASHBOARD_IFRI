@php
    use App\Enums\UserLevel;
    use App\Support\Modules;
@endphp
<div class="flex flex-col gap-6">
    <x-page-header title="System Users" subtitle="Accounts, levels and module access">
        <button type="button" wire:click="create" class="flex h-11 items-center gap-2 rounded-xl bg-green-700 px-4 text-sm font-bold text-white hover:bg-green-800">
            <x-icon name="plus" size="18" /> Add user
        </button>
    </x-page-header>

    {{-- Counts by level --}}
    <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
        @foreach (UserLevel::cases() as $level)
            <div class="flex flex-col gap-1 rounded-2xl border border-line bg-white px-[18px] py-4">
                <span class="text-[12.5px] font-semibold text-muted">{{ $level->label() }}s</span>
                <span class="text-2xl font-extrabold">{{ number_format($counts[$level->value] ?? 0) }}</span>
            </div>
        @endforeach
        <div class="flex flex-col gap-1 rounded-2xl bg-amber-50 px-[18px] py-4">
            <span class="text-[12.5px] font-semibold text-amber-900">Awaiting email verification</span>
            <span class="text-2xl font-extrabold text-amber-900">{{ number_format($pending) }}</span>
        </div>
    </div>

    <section>
        <div class="flex min-w-0 flex-col gap-3.5 rounded-2xl border border-line bg-white p-[18px]">
            <div class="flex flex-wrap items-center gap-2.5">
                <label class="flex h-[42px] min-w-0 flex-[1_1_220px] items-center gap-2 rounded-[10px] border border-field px-3 text-muted focus-within:border-green-700">
                    <x-icon name="search" size="18" />
                    <span class="sr-only">Search users</span>
                    <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search name or email" class="w-full min-w-0 bg-transparent text-sm text-ink outline-none">
                </label>
                <label class="sr-only" for="levelFilter">Filter by level</label>
                <select id="levelFilter" wire:model.live="levelFilter" class="h-[42px] rounded-[10px] border border-field bg-white px-2.5 text-sm">
                    <option value="">All levels</option>
                    @foreach (UserLevel::options() as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="overflow-x-auto rounded-xl border border-line">
                <table class="w-full border-collapse text-[13.5px]">
                    <thead class="bg-[#F6FAF7] text-xs font-bold text-muted">
                        <tr>
                            <th rowspan="2" class="px-3.5 py-2.5 text-left whitespace-nowrap">User</th>
                            <th rowspan="2" class="px-3.5 py-2.5 text-left">Level</th>
                            <th rowspan="2" class="px-3.5 py-2.5 text-left">Status</th>
                            <th colspan="{{ count(Modules::ALL) }}" class="px-3.5 pt-2.5 pb-1 text-center tracking-[0.06em] text-green-700">MODULE ACCESS</th>
                            <th rowspan="2"><span class="sr-only">Actions</span></th>
                        </tr>
                        <tr>
                            @foreach (Modules::ALL as $module)
                                <th class="px-2.5 pt-1 pb-2.5 text-[11.5px] whitespace-nowrap">{{ $module['short'] }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($users as $row)
                            <tr wire:key="user-{{ $row->id }}" class="border-t border-[#EEF3EF]">
                                <td class="px-3.5 py-3">
                                    <div class="flex items-center gap-2.5">
                                        <span class="flex size-[34px] shrink-0 items-center justify-center rounded-full bg-green-100 text-[12.5px] font-extrabold text-green-900">{{ $row->initials }}</span>
                                        <div class="flex min-w-0 flex-col">
                                            <strong class="text-sm whitespace-nowrap">{{ $row->name }}@if ($row->id === auth()->id()) <span class="font-medium text-muted">(you)</span>@endif</strong>
                                            <span class="text-[12.5px] text-muted">{{ $row->email }}</span>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-3.5 py-3"><span class="rounded-full px-2.5 py-1 text-xs font-bold whitespace-nowrap {{ $row->level->badgeClasses() }}">{{ $row->level->label() }}</span></td>
                                <td class="px-3.5 py-3">
                                    <div class="flex flex-col items-start gap-1">
                                        @if (!$row->is_active)
                                            <span class="rounded-full bg-gray-200 px-2.5 py-1 text-xs font-bold whitespace-nowrap text-gray-800">Inactive</span>
                                        @elseif ($row->hasVerifiedEmail())
                                            <span class="rounded-full bg-green-50 px-2.5 py-1 text-xs font-bold whitespace-nowrap text-green-800">Verified</span>
                                        @else
                                            <span class="rounded-full bg-amber-100 px-2.5 py-1 text-xs font-bold whitespace-nowrap text-amber-900">Pending</span>
                                        @endif
                                    </div>
                                </td>
                                @foreach (Modules::ALL as $key => $module)
                                    @php($locked = $row->isAdministrator() || in_array($key, Modules::ADMIN_ONLY, true))
                                    <td class="px-2.5 py-3 text-center">
                                        <input
                                            type="checkbox"
                                            @checked($row->canAccess($key))
                                            @disabled($locked)
                                            wire:click="toggleModule({{ $row->id }}, '{{ $key }}')"
                                            wire:loading.attr="disabled"
                                            aria-label="{{ $module['label'] }} access for {{ $row->name }}"
                                            title="{{ $locked ? ($row->isAdministrator() ? 'Administrators always have every module' : 'Administrators only') : $module['label'] }}"
                                            class="size-[18px] accent-green-700 disabled:cursor-not-allowed disabled:opacity-60"
                                        >
                                    </td>
                                @endforeach
                                <td class="px-3.5 py-3 text-right">
                                    <button type="button" wire:click="edit({{ $row->id }})" class="inline-flex h-9 items-center gap-1.5 rounded-[9px] border border-field bg-white px-3 text-[13px] font-bold text-green-700 hover:border-green-700">
                                        <x-icon name="edit" size="15" /> Edit
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="{{ count(Modules::ALL) + 4 }}" class="px-4 py-10 text-center text-muted">No users match your search.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-2">
                <span class="text-[12.5px] text-muted">Ticking a box changes that user's access right away. Administrators always have every module.</span>
            </div>
            {{ $users->links() }}
        </div>

    </section>

        {{-- Add / edit panel: slides over from the right so the table keeps its width --}}
        @if ($showForm)
            <div class="fixed inset-0 z-40 bg-forest-950/40" wire:click="closeForm" aria-hidden="true"></div>
            <form wire:submit="save" x-data x-on:keydown.escape.window="$wire.closeForm()" role="dialog" aria-modal="true" aria-label="{{ $editingId ? 'Edit user' : 'Add user' }}"
                class="fixed inset-y-0 right-0 z-50 flex w-full max-w-[420px] flex-col gap-3.5 overflow-y-auto bg-white p-6 shadow-2xl" novalidate>
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-bold">{{ $editingId ? 'Edit user' : 'Add user' }}</h2>
                    <button type="button" wire:click="closeForm" class="flex size-10 items-center justify-center rounded-[10px] bg-canvas text-ink-soft hover:text-ink" aria-label="Close"><x-icon name="x" size="18" /></button>
                </div>

                <x-field model="form.first_name" :blur="true" :valid="$this->isValid('form.first_name')" label="First name" />
                <div class="grid grid-cols-2 gap-2.5">
                    <x-field model="form.middle_name" :blur="true" :valid="$this->isValid('form.middle_name')" label="Middle name" placeholder="Optional" />
                    <x-field model="form.last_name" :blur="true" :valid="$this->isValid('form.last_name')" label="Last name" />
                </div>
                <x-field model="form.email" :blur="true" :valid="$this->isValid('form.email')" valid-message="Valid email address." label="Email (username)" type="email" />
                <x-field model="form.mobile_number" label="Mobile number" :mobile="true" :blur="true" :valid="$this->isValid('form.mobile_number')" valid-message="Valid mobile number." placeholder="09XXXXXXXXX" />

                <div class="flex flex-col gap-1.5">
                    <label for="form_level" class="text-[13px] font-semibold text-ink-soft">Level</label>
                    <select id="form_level" wire:model.live="form.level" class="h-[42px] rounded-[10px] border border-field bg-white px-2.5 text-sm">
                        @foreach (UserLevel::options() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('form.level') <p class="text-[12.5px] font-medium text-red-700">{{ $message }}</p> @enderror
                </div>

                <fieldset class="grid grid-cols-2 gap-2.5 rounded-xl border border-line px-3.5 pt-2 pb-3">
                    <legend class="px-1.5 text-xs font-bold text-green-700">MODULE ACCESS</legend>
                    @foreach (Modules::ALL as $key => $module)
                        @php($locked = $formLevel === UserLevel::Administrator || in_array($key, Modules::ADMIN_ONLY, true))
                        <label class="flex items-center gap-2 text-[13.5px] {{ $locked ? 'text-muted' : '' }}">
                            <input type="checkbox" value="{{ $key }}" wire:model="form.modules" @disabled($locked)
                                @if ($formLevel === UserLevel::Administrator) checked @endif
                                class="size-[18px] accent-green-700 disabled:opacity-60">
                            {{ $module['label'] }}
                        </label>
                    @endforeach
                </fieldset>
                @if ($formLevel === UserLevel::Administrator)
                    <p class="-mt-1.5 text-[12px] text-muted">Administrators always have every module.</p>
                @endif

                @if ($editingId)
                    <label class="flex items-center gap-2 text-[13.5px] font-semibold">
                        <input type="checkbox" wire:model="form.is_active" class="size-[18px] accent-green-700">
                        Account is active
                    </label>
                    @error('form.is_active') <p class="text-[12.5px] font-medium text-red-700">{{ $message }}</p> @enderror
                    <p class="text-[12px] text-muted">An inactive account is signed out and can't sign in until reactivated.</p>
                @else
                    <x-alert type="info">On creation the user gets an email with a link to set their own password.</x-alert>
                @endif

                <div class="flex gap-2.5">
                    <button type="button" wire:click="closeForm" class="h-11 flex-1 rounded-[11px] border border-field bg-white text-sm font-bold text-ink-soft hover:border-green-700">Cancel</button>
                    <button type="submit" class="h-11 flex-1 rounded-[11px] bg-green-700 text-sm font-bold text-white hover:bg-green-800 disabled:opacity-70" wire:loading.attr="disabled" wire:target="save">
                        <span wire:loading.remove wire:target="save">{{ $editingId ? 'Save changes' : 'Create user' }}</span>
                        <span wire:loading wire:target="save">Saving…</span>
                    </button>
                </div>
            </form>
        @endif
</div>
