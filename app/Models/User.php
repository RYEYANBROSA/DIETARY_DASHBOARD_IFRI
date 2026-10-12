<?php

namespace App\Models;

use App\Enums\UserLevel;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use App\Support\Modules;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'first_name',
        'middle_name',
        'last_name',
        'email',
        'mobile_number',
        'level',
        'modules',
        'is_active',
        'password',
        'created_by',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $attributes = [
        'level' => 'CLIENT',
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'level' => UserLevel::class,
            'modules' => 'array',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // A new account starts with its level's default modules
        static::creating(function (User $user) {
            if ($user->modules === null) {
                $user->modules = Modules::defaultsFor($user->level);
            }
        });
    }

    /** "Juan S. Dela Cruz" */
    protected function name(): Attribute
    {
        return Attribute::get(function () {
            $middle = trim((string) $this->middle_name);

            return trim($this->first_name . ' ' . ($middle !== '' ? mb_substr($middle, 0, 1) . '. ' : '') . $this->last_name);
        });
    }

    protected function initials(): Attribute
    {
        return Attribute::get(fn () => mb_strtoupper(mb_substr((string) $this->first_name, 0, 1) . mb_substr((string) $this->last_name, 0, 1)));
    }

    public function isAdministrator(): bool
    {
        return $this->level === UserLevel::Administrator;
    }

    public function canAccess(string $module): bool
    {
        if (!array_key_exists($module, Modules::ALL)) {
            return false;
        }

        if ($this->isAdministrator()) {
            return true;
        }

        if (in_array($module, Modules::ADMIN_ONLY, true)) {
            return false;
        }

        return in_array($module, $this->modules ?? [], true);
    }

    /** Route name of the first module this account can open - where sign-in lands. */
    public function homeRoute(): string
    {
        foreach (Modules::ALL as $key => $module) {
            if ($this->canAccess($key)) {
                return $module['route'];
            }
        }

        return 'no-access';
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmailNotification);
    }

    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }
}
