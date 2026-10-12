<div class="flex flex-col gap-5">
    <span class="flex size-14 items-center justify-center rounded-2xl bg-green-100 text-green-800"><x-icon name="mail" size="28" /></span>
    <div class="flex flex-col gap-2">
        <h2 class="text-[26px] font-extrabold">Check your inbox</h2>
        <p class="text-sm leading-relaxed text-muted">We sent a verification link to <strong class="text-ink">{{ auth()->user()->email }}</strong>. Open it to activate your account. The link works on any device.</p>
    </div>

    @if (session('error'))
        <x-alert type="error">{{ session('error') }}</x-alert>
    @endif

    <p class="text-[13.5px] text-muted">Didn't get it? Check your spam folder, or send a new link.</p>

    <div class="flex flex-wrap gap-3">
        <button type="button" wire:click="resend" wire:loading.attr="disabled" class="flex h-11 items-center justify-center rounded-xl bg-green-700 px-5 text-sm font-bold text-white hover:bg-green-800 disabled:opacity-70">
            <span wire:loading.remove wire:target="resend">Resend verification email</span>
            <span wire:loading wire:target="resend">Sending…</span>
        </button>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="flex h-11 items-center justify-center rounded-xl border border-field bg-white px-5 text-sm font-bold text-ink-soft hover:border-green-700">Sign out</button>
        </form>
    </div>
</div>
