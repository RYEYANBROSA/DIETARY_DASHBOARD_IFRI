<?php

namespace Database\Seeders;

use App\Enums\UserLevel;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Demo accounts for trying the app locally - one per level. Never seeded
     * outside the local environment; real accounts register themselves and the
     * first administrator is promoted with `php artisan user:make-admin`.
     */
    public function run(): void
    {
        if (!app()->environment('local')) {
            $this->command?->warn('Demo accounts are only seeded in the local environment.');

            return;
        }

        $accounts = [
            ['Demo', 'Admin', 'admin@dietary.test', UserLevel::Administrator],
            ['Demo', 'Validator', 'validator@dietary.test', UserLevel::Validator],
            ['Demo', 'Client', 'client@dietary.test', UserLevel::Client],
        ];

        foreach ($accounts as [$first, $last, $email, $level]) {
            $user = User::updateOrCreate(['email' => $email], [
                'first_name' => $first,
                'last_name' => $last,
                'level' => $level,
                'modules' => $level->defaultModules(),
                'is_active' => true,
                // Local demo password for all three accounts
                'password' => 'Demo@2026!',
            ]);

            if (!$user->hasVerifiedEmail()) {
                $user->markEmailAsVerified();
            }
        }
    }
}
