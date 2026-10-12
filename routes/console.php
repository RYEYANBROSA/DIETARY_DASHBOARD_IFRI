<?php

use App\Enums\UserLevel;
use App\Models\User;
use App\Support\Modules;
use Illuminate\Support\Facades\Artisan;

/*
 * Registration only ever creates Client accounts, so the first administrator
 * is made here: register normally, then promote that account.
 *   php artisan user:make-admin someone@example.com
 */
Artisan::command('user:make-admin {email}', function (string $email) {
    $user = User::where('email', $email)->first();

    if (!$user) {
        $this->error("No account found for {$email}. Register it first.");

        return 1;
    }

    $user->forceFill([
        'level' => UserLevel::Administrator,
        'modules' => Modules::keys(),
        'is_active' => true,
    ])->save();

    $this->info("{$user->name} <{$user->email}> is now an Administrator.");

    if (!$user->hasVerifiedEmail()) {
        $this->warn('This account has not verified its email yet - it must do so before signing in.');
    }

    return 0;
})->purpose('Promote an existing account to Administrator');

/*
 * Create the final-schema survey tables (f11, f72, household_listing,
 * LocalArea) on the survey connection, for a fresh survey database. Tables
 * that already exist are left untouched.
 *   php artisan survey:install-schema
 */
Artisan::command('survey:install-schema', function () {
    $database = config('database.connections.survey.database');
    $created = \App\Support\Survey::install();

    if ($created === []) {
        $this->info("All four survey tables already exist in {$database} - nothing to do.");
    } else {
        $this->info("Created in {$database}: " . implode(', ', $created));
    }

    return 0;
})->purpose('Create any missing survey tables (final DDCS schema)');

/*
 * Copy the survey tables from another database on the same MySQL server into
 * the survey connection's database - e.g. the old ddcs_dashboard copy as dummy
 * data for development. Only columns both sides have are copied; the target
 * tables must be empty unless --fresh is given (which empties them first).
 *   php artisan survey:copy-from ddcs_dashboard
 */
Artisan::command('survey:copy-from {source} {--fresh : Empty the target tables first}', function (string $source) {
    $db = \App\Support\Survey::db();
    $target = $db->getDatabaseName();

    if (strcasecmp($source, $target) === 0) {
        $this->error('The source and the survey database are the same.');

        return 1;
    }

    $tables = ['LocalArea' => ['LocalArea', 'localarea'], 'household_listing' => ['household_listing'], 'f11' => ['f11'], 'f72' => ['f72']];

    foreach ($tables as $name => $candidates) {
        $to = $name === 'LocalArea' ? \App\Support\Survey::localAreaTable() : $name;
        $from = collect($candidates)->first(fn ($t) => $db->selectOne('SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = ? AND table_name = ?', [$source, $t])->n > 0);

        if (!$to || !$from) {
            $this->warn("{$name}: skipped (missing in " . (!$to ? $target : $source) . ').');

            continue;
        }

        if ($db->table($to)->exists()) {
            if (!$this->option('fresh')) {
                $this->warn("{$name}: skipped - {$target}.{$to} already has rows (use --fresh to replace them).");

                continue;
            }
            $db->table($to)->delete();
        }

        $sourceColumns = collect($db->select('SELECT column_name AS c FROM information_schema.columns WHERE table_schema = ? AND table_name = ?', [$source, $from]))->pluck('c')->map(fn ($c) => strtolower($c));
        $columns = collect(\App\Support\Survey::columns($to))->filter(fn ($c) => $sourceColumns->contains(strtolower($c)))->values();
        $list = $columns->map(fn ($c) => "`{$c}`")->implode(', ');

        $copied = $db->affectingStatement("INSERT INTO `{$to}` ({$list}) SELECT {$list} FROM `{$source}`.`{$from}` ORDER BY `id`");
        $left = collect(\App\Support\Survey::columns($to))->diff($columns)->implode(', ');

        $this->info("{$name}: copied {$copied} rows from {$source}.{$from}" . ($left ? " (left blank: {$left})" : ''));
    }

    \Illuminate\Support\Facades\Cache::flush();

    return 0;
})->purpose('Copy survey data from another database on the same server');
