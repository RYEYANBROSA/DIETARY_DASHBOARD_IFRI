{{--
    Bridge to the SweetAlert2 alerts (resources/js/alerts.js). Livewire components call
        $this->dispatch('toast', message: '...', type: 'success'|'error'|'warning'|'info');
    optionally with title: '...', persist: true (a success that waits for OK) or
    small: true (a corner toast for quick repeated actions),
    and a redirect can flash session('toast').
--}}
<div
    x-data
    x-init="@if (session('toast')) $nextTick(() => window.notify({ message: @js(session('toast')), type: 'success' })) @endif"
    x-on:toast.window="window.notify($event.detail)"
    hidden
></div>
