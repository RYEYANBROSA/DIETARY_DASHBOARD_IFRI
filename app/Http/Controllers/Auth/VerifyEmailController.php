<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * The link in the verification email. Unlike Laravel's stock route this does
 * not need the user to be signed in - people often open the email on another
 * device - the signed URL plus the email hash are what prove it.
 */
class VerifyEmailController extends Controller
{
    public function __invoke(string $id, string $hash): RedirectResponse
    {
        $user = User::findOrFail($id);

        if (!hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            abort(403, 'This verification link is not valid.');
        }

        if (!$user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
        }

        if (Auth::id() === $user->id) {
            return redirect()->route($user->homeRoute())
                ->with('toast', 'Your email address is verified.');
        }

        return redirect()->route('login')
            ->with('status', 'Your email address is verified. You can now sign in.');
    }
}
