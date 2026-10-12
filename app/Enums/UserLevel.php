<?php

namespace App\Enums;

use App\Support\Modules;

enum UserLevel: string
{
    case Client = 'CLIENT';
    case Validator = 'VALIDATOR';
    case Administrator = 'ADMINISTRATOR';

    public function label(): string
    {
        return ucfirst(strtolower($this->value));
    }

    /** Modules an account of this level gets until an administrator changes them. */
    public function defaultModules(): array
    {
        return Modules::defaultsFor($this);
    }

    /** Badge classes for the level chip. */
    public function badgeClasses(): string
    {
        return match ($this) {
            self::Administrator => 'bg-forest-900 text-white',
            self::Validator => 'bg-blue-100 text-blue-900',
            self::Client => 'bg-green-100 text-green-900',
        };
    }

    /** @return array<string, string> value => label, for selects */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
