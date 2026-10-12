<div class="flex flex-col gap-5">
    <div class="flex flex-col gap-2">
        <h2 class="text-[26px] font-extrabold">Set your password</h2>
        <p class="text-sm text-muted">Choose a strong password for your Dietary Dashboard account.</p>
    </div>

    <form wire:submit="resetPassword" class="flex flex-col gap-4" novalidate>
        <x-field model="email" label="Email" type="email" :blur="true" :valid="$this->isValid('email')" valid-message="Valid email address." autocomplete="username" />
        <x-password-input model="password" label="New password" :meter="true" />
        <x-password-input model="password_confirmation" label="Confirm new password" />

        <button type="submit" class="flex h-12 items-center justify-center rounded-xl bg-green-700 text-[15px] font-bold text-white hover:bg-green-800 disabled:opacity-70" wire:loading.attr="disabled">
            <span wire:loading.remove wire:target="resetPassword">Save password</span>
            <span wire:loading wire:target="resetPassword">Saving…</span>
        </button>
    </form>

    <a href="{{ route('login') }}" class="self-center text-sm font-bold text-green-700 hover:text-green-900">Back to sign in</a>
</div>
