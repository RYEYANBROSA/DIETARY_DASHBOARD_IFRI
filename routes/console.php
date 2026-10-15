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

/*
 * Re-key the live server's sws_data the same way as ours: unique on
 * identifier1 + identifier2 + identifier3 + sws_member_no instead of
 * sws_member_no alone. SWS Upload Data's Transmit waits until this is done.
 * Without --apply it only reports what it would change.
 *   php artisan sws:live-key           (look)
 *   php artisan sws:live-key --apply   (change the live server)
 */
Artisan::command('sws:live-key {--apply : Change the live server}', function () {
    $live = \App\Support\SwsStore::live();
    $where = $live->getConfig('host') . ':' . $live->getConfig('port') . '/' . $live->getDatabaseName();

    if (!$live->getSchemaBuilder()->hasTable('sws_data')) {
        $this->error("There is no sws_data table on the live server ({$where}).");

        return 1;
    }

    $this->line("Live server: {$where} - sws_data has " . $live->table('sws_data')->count() . ' row(s).');
    foreach ($live->getSchemaBuilder()->getIndexes('sws_data') as $index) {
        $this->line(sprintf('  %-26s %-8s (%s)', $index['name'], $index['unique'] ? 'unique' : 'index', implode(', ', $index['columns'])));
    }

    $this->line($live->getSchemaBuilder()->hasTable('listing_version')
        ? 'listing_version: ' . (\App\Support\SwsStore::listingVersion() ?? 'empty - the first transmission sets it to 1')
        : 'listing_version: missing - transmissions still go through, but the version is not raised.');

    if (\App\Support\SwsStore::liveKeyReady()) {
        $this->info('Already keyed on identifier1 + identifier2 + identifier3 + sws_member_no - nothing to do.');

        return 0;
    }

    if (!$this->option('apply')) {
        $this->warn('Would: set blank identifiers to \'\', add unique sws_data_key_unique (identifier1, identifier2, identifier3, sws_member_no), drop the unique index on sws_member_no alone, and keep a plain index on sws_member_no.');
        $this->line('Run again with --apply to make the change. No rows are added or removed.');

        return 0;
    }

    foreach (\App\Support\Survey::rekeySwsData($live) as $step) {
        $this->info('Done: ' . $step);
    }

    return \App\Support\SwsStore::liveKeyReady() ? 0 : 1;
})->purpose("Key the live server's sws_data on identifiers 1-3 + SWS member no.");
