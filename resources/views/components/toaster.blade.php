{{--
    Toast notifications. Livewire components call
        $this->dispatch('toast', message: '...', type: 'success'|'error'|'info');
    and a redirect can flash session('toast').
--}}
<div
    x-data="{
        toasts: [],
        add(detail) {
            const id = Date.now() + Math.random();
            this.toasts.push({ id, message: detail.message, type: detail.type || 'success' });
            setTimeout(() => this.toasts = this.toasts.filter(t => t.id !== id), 5000);
        },
    }"
    x-init="@if (session('toast')) add({ message: @js(session('toast')), type: 'success' }) @endif"
    x-on:toast.window="add($event.detail)"
    class="pointer-events-none fixed inset-x-4 bottom-4 z-50 flex flex-col items-end gap-2 sm:inset-x-auto sm:right-6"
    role="status"
    aria-live="polite"
>
    <template x-for="toast in toasts" :key="toast.id">
        <div
            x-transition.opacity
            class="pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-xl px-4 py-3 text-sm font-semibold shadow-lg"
            x-bind:class="{
                'bg-forest-900 text-white': toast.type === 'success',
                'bg-red-700 text-white': toast.type === 'error',
                'bg-white text-ink border border-line': toast.type === 'info',
            }"
        >
            <span class="flex-1" x-text="toast.message"></span>
            <button type="button" class="-m-1 rounded p-1 opacity-80 hover:opacity-100" x-on:click="toasts = toasts.filter(t => t.id !== toast.id)" aria-label="Dismiss">
                <x-icon name="x" size="16" />
            </button>
        </div>
    </template>
</div>
