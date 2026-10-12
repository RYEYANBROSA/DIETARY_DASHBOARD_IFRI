@props(['type' => 'success'])
@php
    $styles = [
        'success' => 'bg-green-50 text-green-900 border-green-200',
        'error' => 'bg-red-50 text-red-900 border-red-200',
        'info' => 'bg-[#ECFDF3] text-forest-900 border-line',
    ][$type] ?? '';
    $icon = ['success' => 'check', 'error' => 'alert', 'info' => 'mail'][$type] ?? 'alert';
@endphp
<div {{ $attributes->merge(['class' => "flex items-start gap-2.5 rounded-xl border px-3.5 py-3 text-[13.5px] leading-relaxed {$styles}"]) }} role="{{ $type === 'error' ? 'alert' : 'status' }}">
    <x-icon :name="$icon" size="18" class="mt-0.5" />
    <div>{{ $slot }}</div>
</div>
