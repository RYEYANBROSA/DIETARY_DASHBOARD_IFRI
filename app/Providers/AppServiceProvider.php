<?php

namespace App\Providers;

use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureModuleAccess;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The strength rules shown live under every new-password field
        Password::defaults(fn () => Password::min(8)->mixedCase()->numbers()->symbols());

        // Re-run these on every Livewire action, not only the first page load,
        // so revoking a module or deactivating an account takes effect at once
        Livewire::addPersistentMiddleware([
            EnsureAccountIsActive::class,
            EnsureModuleAccess::class,
        ]);
    }
}
