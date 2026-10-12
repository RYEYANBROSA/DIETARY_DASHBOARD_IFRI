<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * route->middleware('module:f11') - only accounts granted that module get in.
 * Registered as Livewire persistent middleware too, so a component's actions
 * are re-checked on every update, not just on the first page load.
 */
class EnsureModuleAccess
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        $user = $request->user();

        if (!$user || !$user->canAccess($module)) {
            abort(403, 'Your account does not have access to this module.');
        }

        return $next($request);
    }
}
