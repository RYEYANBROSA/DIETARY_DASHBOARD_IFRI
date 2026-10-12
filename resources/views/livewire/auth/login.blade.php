<div>
    <x-auth-tabs active="login" />

    <form wire:submit="login" class="flex flex-col gap-[18px]" novalidate>
        <div class="flex flex-col gap-1.5">
            <h2 class="text-[26px] font-extrabold">Welcome back</h2>
            <p class="text-sm text-muted">Sign in with the email you registered.</p>
        </div>

        @if (session('status'))
            <x-alert>{{ session('status') }}</x-alert>
        @endif
        @if (session('error'))
            <x-alert type="error">{{ session('error') }}</x-alert>
        @endif

        <x-field model="email" label="Email" type="email" :blur="true" :valid="$this->isValid('email')" valid-message="Valid email address." autocomplete="username" placeholder="you@example.com" autofocus />
        <x-password-input model="password" label="Password" autocomplete="current-password" placeholder="Enter your password" />

        <div class="flex items-center justify-between gap-3 text-sm">
            <label class="flex items-center gap-2 text-ink-soft">
                <input type="checkbox" wire:model="remember" class="size-[18px] accent-green-700">
                Remember me
            </label>
            <a href="{{ route('password.request') }}" class="font-semibold text-green-700 hover:text-green-900">Forgot password?</a>
        </div>

        <button type="submit" class="flex h-12 items-center justify-center gap-2 rounded-xl bg-green-700 text-[15px] font-bold text-white transition hover:bg-green-800 disabled:opacity-70" wire:loading.attr="disabled">
            <span wire:loading.remove wire:target="login">Sign in</span>
            <span wire:loading wire:target="login">Signing in…</span>
        </button>

        <p class="text-center text-sm text-muted">New here? <a href="{{ route('register') }}" class="font-bold text-green-700 hover:text-green-900">Create an account</a></p>
    </form>
</div>
