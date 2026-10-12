@props([
    'model',
    'label',
    'meter' => false,
    'autocomplete' => 'new-password',
    'placeholder' => '',
])
{{--
    Password field with a show/hide eye. With :meter="true" it also shows a
    live strength bar and the five rules the server enforces
    (Password::defaults in AppServiceProvider): 8+ characters, an uppercase
    and a lowercase letter, a number and a special character.
--}}
@php($id = str_replace('.', '_', $model))
<div
    x-data="{
        show: false,
        // Mirrors the Livewire property, so a server-side reset clears the meter too
        value: $wire.entangle(@js($model)),
        get rules() {
            const v = this.value || '';
            return [
                { label: '8+ characters', ok: v.length >= 8 },
                { label: 'Uppercase letter', ok: /[A-Z]/.test(v) },
                { label: 'Lowercase letter', ok: /[a-z]/.test(v) },
                { label: 'Number', ok: /[0-9]/.test(v) },
                { label: 'Special character', ok: /[^A-Za-z0-9]/.test(v) },
            ];
        },
        get score() { return this.rules.filter(r => r.ok).length; },
        get strength() { return !this.value ? 'Not set' : ['Too weak', 'Too weak', 'Weak', 'Fair', 'Good', 'Strong'][this.score]; },
        get color() { return !this.value ? '#4B5E54' : ['#B91C1C', '#B91C1C', '#C2410C', '#A16207', '#15803D', '#166534'][this.score]; },
    }"
    {{ $attributes->merge(['class' => 'flex flex-col gap-1.5']) }}
>
    <label for="{{ $id }}" class="text-[13px] font-semibold text-ink-soft">{{ $label }}</label>
    <div class="relative">
        <input
            id="{{ $id }}"
            type="password"
            x-bind:type="show ? 'text' : 'password'"
            x-model="value"
            autocomplete="{{ $autocomplete }}"
            placeholder="{{ $placeholder }}"
            @error($model) aria-invalid="true" @enderror
            class="h-11 w-full rounded-[10px] border bg-white pl-3 pr-12 text-sm text-ink outline-none transition focus:border-green-700 focus:ring-3 focus:ring-green-700/15 @error($model) border-red-600 @else border-field @enderror"
        >
        <button
            type="button"
            x-on:click="show = !show"
            x-bind:aria-label="show ? 'Hide password' : 'Show password'"
            aria-label="Show password"
            class="absolute inset-y-0 right-0 flex w-11 items-center justify-center rounded-r-[10px] text-muted hover:text-green-800 focus-visible:outline-2 focus-visible:outline-green-700"
        >
            <x-icon name="eye" x-show="!show" />
            <x-icon name="eye-off" x-show="show" x-cloak />
        </button>
    </div>

    @if ($meter)
        <div class="mt-1 flex flex-col gap-2 rounded-xl border border-line bg-[#F6FAF7] px-3.5 py-3" aria-live="polite">
            <div class="flex gap-1.5">
                <template x-for="i in 5" :key="i">
                    <span class="h-1.5 flex-1 rounded-full" x-bind:style="'background:' + (i <= score ? color : '#E2EBE4')"></span>
                </template>
            </div>
            <div class="flex justify-between text-[12.5px] text-muted">
                <span>Password strength</span>
                <span class="font-bold" x-bind:style="'color:' + color" x-text="strength"></span>
            </div>
            <ul class="grid grid-cols-2 gap-x-3 gap-y-1.5 sm:grid-cols-3">
                <template x-for="rule in rules" :key="rule.label">
                    <li class="flex items-center gap-2 text-[12.5px] font-semibold" x-bind:class="rule.ok ? 'text-green-800' : 'text-muted'">
                        <span class="flex size-4 shrink-0 items-center justify-center rounded-full text-white" x-bind:class="rule.ok ? 'bg-green-700' : 'bg-[#CBD8CF]'">
                            <svg x-show="rule.ok" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12l5 5 9-10"/></svg>
                        </span>
                        <span x-text="rule.label"></span>
                    </li>
                </template>
            </ul>
        </div>
    @endif

    @error($model)
        <p class="text-[12.5px] font-medium text-red-700">{{ $message }}</p>
    @enderror
</div>
