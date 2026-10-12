<div class="flex flex-col gap-6">
    <x-page-header title="My Profile" subtitle="Your account details and password" />

    <section class="flex flex-wrap items-start gap-4">
        {{-- Summary card --}}
        <div class="flex min-w-[260px] flex-[1_1_280px] flex-col overflow-hidden rounded-2xl border border-line bg-white">
            <div class="h-[88px] bg-forest-900"></div>
            <div class="-mt-10 flex flex-col gap-3 px-6 pb-6">
                <span class="flex size-20 items-center justify-center rounded-full border-4 border-white bg-mint-300 text-[26px] font-extrabold text-forest-900">{{ $user->initials }}</span>
                <div class="flex flex-col gap-0.5">
                    <strong class="text-[19px]">{{ $user->name }}</strong>
                    <span class="break-all text-[13.5px] text-muted">{{ $user->email }}</span>
                </div>
                <div class="flex flex-wrap gap-2">
                    <span class="rounded-full px-2.5 py-1 text-xs font-bold {{ $user->level->badgeClasses() }}">{{ $user->level->label() }}</span>
                    <span class="rounded-full bg-green-100 px-2.5 py-1 text-xs font-bold text-green-900">Email verified</span>
                </div>
                <dl class="mt-2 flex flex-col gap-2 border-t border-line pt-3 text-[13.5px]">
                    <div class="flex justify-between gap-3"><dt class="text-muted">Mobile</dt><dd class="font-semibold">{{ $user->mobile_number ?: 'Not set' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-muted">Member since</dt><dd class="font-semibold">{{ $user->created_at->format('M j, Y') }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-muted">Last sign-in</dt><dd class="font-semibold">{{ $user->last_login_at?->diffForHumans() ?? '—' }}</dd></div>
                </dl>
                <div class="mt-1 flex flex-col gap-2 border-t border-line pt-3">
                    <span class="text-xs font-bold tracking-[0.08em] text-green-700">YOUR MODULES</span>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach (\App\Support\Modules::ALL as $key => $module)
                            @if ($user->canAccess($key))
                                <span class="rounded-full bg-canvas px-2.5 py-1 text-xs font-semibold text-ink-soft">{{ $module['label'] }}</span>
                            @endif
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        <div class="flex min-w-0 flex-[999_1_480px] flex-col gap-4">
            {{-- Account details --}}
            <form wire:submit="saveDetails" class="flex flex-col gap-4 rounded-2xl border border-line bg-white p-6" novalidate>
                <h2 class="text-lg font-bold">Account details</h2>
                <div class="grid gap-3 sm:grid-cols-3">
                    <x-field model="first_name" :blur="true" :valid="$this->isValid('first_name')" label="First name" autocomplete="given-name" />
                    <x-field model="middle_name" :blur="true" :valid="$this->isValid('middle_name')" label="Middle name" autocomplete="additional-name" placeholder="Optional" />
                    <x-field model="last_name" :blur="true" :valid="$this->isValid('last_name')" label="Last name" autocomplete="family-name" />
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <x-field model="email" :blur="true" :valid="$this->isValid('email')" valid-message="Valid email address." label="Email (your username)" type="email" autocomplete="email" />
                    <x-field model="mobile_number" label="Mobile number" :mobile="true" :blur="true" :valid="$this->isValid('mobile_number')" valid-message="Valid mobile number." autocomplete="tel" placeholder="09XXXXXXXXX" />
                </div>
                <p class="text-[12.5px] text-muted">Changing your email sends a verification link to the new address. You'll need to confirm it before you can continue.</p>
                <div class="flex justify-end">
                    <button type="submit" class="h-11 rounded-[11px] bg-green-700 px-5 text-sm font-bold text-white hover:bg-green-800 disabled:opacity-70" wire:loading.attr="disabled" wire:target="saveDetails">Save details</button>
                </div>
            </form>

            {{-- Password --}}
            <form wire:submit="updatePassword" class="flex flex-col gap-4 rounded-2xl border border-line bg-white p-6" novalidate>
                <h2 class="text-lg font-bold">Change password</h2>
                <x-password-input model="current_password" label="Current password" autocomplete="current-password" />
                <div class="grid gap-3 sm:grid-cols-2">
                    <x-password-input model="password" label="New password" />
                    <x-password-input model="password_confirmation" label="Confirm new password" />
                </div>
                {{-- Strength meter for the new password, shown full width under both fields --}}
                <div
                    x-data="{
                        get v() { return $wire.password || ''; },
                        get rules() {
                            const v = this.v;
                            return [
                                { label: '8+ characters', ok: v.length >= 8 },
                                { label: 'Uppercase letter', ok: /[A-Z]/.test(v) },
                                { label: 'Lowercase letter', ok: /[a-z]/.test(v) },
                                { label: 'Number', ok: /[0-9]/.test(v) },
                                { label: 'Special character', ok: /[^A-Za-z0-9]/.test(v) },
                            ];
                        },
                    }"
                    class="flex flex-wrap gap-x-4 gap-y-1.5"
                    aria-live="polite"
                >
                    <template x-for="rule in rules" :key="rule.label">
                        <span class="flex items-center gap-2 text-[12.5px] font-semibold" x-bind:class="rule.ok ? 'text-green-800' : 'text-muted'">
                            <span class="flex size-4 items-center justify-center rounded-full text-white" x-bind:class="rule.ok ? 'bg-green-700' : 'bg-[#CBD8CF]'">
                                <svg x-show="rule.ok" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12l5 5 9-10"/></svg>
                            </span>
                            <span x-text="rule.label"></span>
                        </span>
                    </template>
                </div>
                <div class="flex justify-end">
                    <button type="submit" class="h-11 rounded-[11px] bg-green-700 px-5 text-sm font-bold text-white hover:bg-green-800 disabled:opacity-70" wire:loading.attr="disabled" wire:target="updatePassword">Update password</button>
                </div>
            </form>
        </div>
    </section>
</div>
