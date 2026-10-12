<?php

namespace App\Support;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Validation for the account fields, shared by registration, the profile page
 * and System Users so the three can never drift apart. Messages live in
 * lang/en/validation.php ("This field is required.", the email and mobile
 * wording under 'custom').
 */
final class AccountRules
{
    public static function names(string $prefix = ''): array
    {
        return [
            $prefix . 'first_name' => ['required', 'string', 'max:100'],
            $prefix . 'middle_name' => ['nullable', 'string', 'max:100'],
            $prefix . 'last_name' => ['required', 'string', 'max:100'],
        ];
    }

    /**
     * A well-formed address. "rfc" alone would accept "juan@example" (no dot
     * in the domain); "filter" (PHP's FILTER_VALIDATE_EMAIL) rejects it.
     */
    public static function emailFormat(): array
    {
        return ['required', 'string', 'email:rfc,filter', 'max:255'];
    }

    /** A well-formed address that no other account uses yet. */
    public static function email(?int $ignoreUserId = null): array
    {
        return [...self::emailFormat(), Rule::unique('users', 'email')->ignore($ignoreUserId)];
    }

    /** Philippine mobile number: exactly 11 digits, starting with 09 */
    public static function mobile(): array
    {
        return ['nullable', 'digits:11', 'starts_with:09'];
    }

    public static function newPassword(): array
    {
        return ['required', 'string', 'confirmed', Password::defaults()];
    }
}
