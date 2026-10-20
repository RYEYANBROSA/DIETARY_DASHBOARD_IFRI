<?php

namespace App\Support;

use App\Enums\UserLevel;

/**
 * The modules an account can be given access to. Each level starts with a
 * default set; an administrator can tick modules on or off per account from
 * System Users. Administrators always have every module, and System Users is
 * reserved for administrators - it is where levels are handed out.
 */
final class Modules
{
    public const DASHBOARD = 'dashboard';
    public const F11 = 'f11';
    public const F72 = 'f72';
    public const SWS = 'sws';
    public const PROFILE = 'profile';
    public const USERS = 'users';

    /** key => [label, short label, route name, icon], in sidebar order */
    public const ALL = [
        self::DASHBOARD => ['label' => 'Dashboard', 'short' => 'Dashboard', 'route' => 'dashboard', 'icon' => 'grid'],
        self::F11 => ['label' => 'F11 Membership', 'short' => 'F11', 'route' => 'f11', 'icon' => 'users'],
        self::F72 => ['label' => 'F72 Food Recall', 'short' => 'F72', 'route' => 'f72', 'icon' => 'table'],
        self::SWS => ['label' => 'SWS Upload Data', 'short' => 'SWS', 'route' => 'sws', 'icon' => 'database'],
        self::PROFILE => ['label' => 'My Profile', 'short' => 'Profile', 'route' => 'profile', 'icon' => 'user'],
        self::USERS => ['label' => 'System Users', 'short' => 'Users', 'route' => 'users', 'icon' => 'users'],
    ];

    /** Modules no non-administrator can be granted. */
    public const ADMIN_ONLY = [self::USERS];

    public static function keys(): array
    {
        return array_keys(self::ALL);
    }

    public static function label(string $key): string
    {
        return self::ALL[$key]['label'] ?? $key;
    }

    public static function defaultsFor(UserLevel $level): array
    {
        return match ($level) {
            UserLevel::Administrator => self::keys(),
            UserLevel::Validator => [self::DASHBOARD, self::F11, self::SWS, self::PROFILE],
            UserLevel::Client => [self::DASHBOARD, self::PROFILE],
        };
    }

    /**
     * Clean a submitted module list for a level: unknown keys dropped,
     * admin-only modules kept off non-administrators, and administrators
     * given everything. Returned in sidebar order.
     */
    public static function normalize(array $modules, UserLevel $level): array
    {
        if ($level === UserLevel::Administrator) {
            return self::keys();
        }

        return array_values(array_filter(
            self::keys(),
            fn ($key) => in_array($key, $modules, true) && !in_array($key, self::ADMIN_ONLY, true)
        ));
    }
}
