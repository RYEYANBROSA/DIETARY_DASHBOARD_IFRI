@props([
    'model',
    'label',
    'type' => 'text',
    'hint' => null,
    // Send the value when the user leaves the field, so the component can check it right away
    'blur' => false,
    // The component's last check of this field passed (see ValidatesOnBlur)
    'valid' => false,
    'validMessage' => null,
    // Mobile number: digits only, at most 11
    'mobile' => false,
])
{{-- Labelled text input bound to a Livewire property, with its error (or valid note) underneath. --}}
@php
    $id = str_replace('.', '_', $model);
    $invalid = $errors->has($model);
    $border = $invalid ? 'border-red-600' : ($valid ? 'border-green-600' : 'border-field');
@endphp
<div class="flex min-w-0 flex-col gap-1.5">
    <label for="{{ $id }}" class="text-[13px] font-semibold text-ink-soft">{{ $label }}</label>
    <div class="relative">
        <input
            id="{{ $id }}"
            type="{{ $mobile ? 'tel' : $type }}"
            @if ($blur) wire:model.blur="{{ $model }}" @else wire:model="{{ $model }}" @endif
            @if ($mobile)
                inputmode="numeric"
                maxlength="11"
                x-data
                x-on:input="$el.value = $el.value.replace(/\D/g, '').slice(0, 11)"
            @endif
            @if ($invalid) aria-invalid="true" aria-describedby="{{ $id }}_error" @elseif ($valid && $validMessage) aria-describedby="{{ $id }}_valid" @endif
            {{ $attributes->merge(['class' => "h-11 w-full min-w-0 rounded-[10px] border bg-white px-3 text-sm text-ink outline-none transition focus:border-green-700 focus:ring-3 focus:ring-green-700/15 {$border}" . ($valid && !$invalid ? ' pr-10' : '')]) }}
        >
        @if ($valid && !$invalid)
            <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-green-700"><x-icon name="check" size="18" /></span>
        @endif
    </div>
    @if ($hint && !$invalid)
        <p class="text-[12px] text-muted">{{ $hint }}</p>
    @endif
    @error($model)
        <p id="{{ $id }}_error" class="text-[12.5px] font-medium text-red-700">{{ $message }}</p>
    @else
        @if ($valid && $validMessage)
            <p id="{{ $id }}_valid" class="text-[12.5px] font-medium text-green-700">{{ $validMessage }}</p>
        @endif
    @enderror
</div>
