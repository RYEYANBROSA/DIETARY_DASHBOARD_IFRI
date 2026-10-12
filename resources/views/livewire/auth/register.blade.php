<div>
    <x-auth-tabs active="register" />

    <form wire:submit="register" class="flex flex-col gap-4" novalidate>
        <div class="flex flex-col gap-1.5">
            <h2 class="text-[26px] font-extrabold">Create your account</h2>
            <p class="text-sm text-muted">Your email becomes your username.</p>
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
            <x-field model="first_name" :blur="true" :valid="$this->isValid('first_name')" label="First name" autocomplete="given-name" />
            <x-field model="middle_name" :blur="true" :valid="$this->isValid('middle_name')" label="Middle name" autocomplete="additional-name" placeholder="Optional" />
            <x-field model="last_name" :blur="true" :valid="$this->isValid('last_name')" label="Last name" autocomplete="family-name" />
        </div>

        <div class="grid gap-3 sm:grid-cols-2">
            <x-field model="email" :blur="true" :valid="$this->isValid('email')" valid-message="Valid email address." label="Email (your username)" type="email" autocomplete="email" placeholder="you@example.com" />
            <x-field model="mobile_number" label="Mobile number" :mobile="true" :blur="true" :valid="$this->isValid('mobile_number')" valid-message="Valid mobile number." autocomplete="tel" placeholder="09XXXXXXXXX" />
        </div>

        <x-password-input model="password" label="Password" :meter="true" placeholder="Create a strong password" />
        <x-password-input model="password_confirmation" label="Confirm password" placeholder="Type it again" />

        <x-alert type="info">New accounts start as <strong>Client</strong>. We'll email a verification link — confirm it before you sign in.</x-alert>

        <button type="submit" class="flex h-12 items-center justify-center rounded-xl bg-green-700 text-[15px] font-bold text-white transition hover:bg-green-800 disabled:opacity-70" wire:loading.attr="disabled">
            <span wire:loading.remove wire:target="register">Create account</span>
            <span wire:loading wire:target="register">Creating your account…</span>
        </button>

        <p class="text-center text-sm text-muted">Already registered? <a href="{{ route('login') }}" class="font-bold text-green-700 hover:text-green-900">Sign in</a></p>
    </form>
</div>
