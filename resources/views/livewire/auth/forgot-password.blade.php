<div class="flex flex-col gap-5">
    <div class="flex flex-col gap-2">
        <h2 class="text-[26px] font-extrabold">Reset your password</h2>
        <p class="text-sm leading-relaxed text-muted">Enter your account email. We'll send a link that lets you choose a new password. The link expires in {{ config('auth.passwords.users.expire') }} minutes.</p>
    </div>

    @if ($sent)
        <x-alert>If <strong>{{ $email }}</strong> has an account, a reset link is on its way. Check your inbox and spam folder.</x-alert>
    @endif

    <form wire:submit="sendResetLink" class="flex flex-col gap-[18px]" novalidate>
        <x-field model="email" label="Email" type="email" :blur="true" :valid="$this->isValid('email')" valid-message="Valid email address." autocomplete="email" placeholder="you@example.com" />

        <button type="submit" class="flex h-12 items-center justify-center rounded-xl bg-green-700 text-[15px] font-bold text-white hover:bg-green-800 disabled:opacity-70" wire:loading.attr="disabled">
            <span wire:loading.remove wire:target="sendResetLink">Send reset link</span>
            <span wire:loading wire:target="sendResetLink">Sending…</span>
        </button>
    </form>

    <a href="{{ route('login') }}" class="self-center text-sm font-bold text-green-700 hover:text-green-900">Back to sign in</a>
</div>
