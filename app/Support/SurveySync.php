<?php

namespace App\Support;

use App\Models\SurveySyncRun;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Adds the live server's survey rows (mysql_live, where the tablets transmit)
 * to the local survey tables. Only ever reads the live server.
 *
 * ADD ONLY (user rule, 2026-10-21): a fetch inserts the live rows that aren't
 * here yet and does nothing else -
 *   - a row already here is never updated, even if it changed on the live server,
 *   - a row deleted on the live server is never removed here (counted as "kept").
 * Rows are matched by the table's own unique key (KEYS - area, household and
 * member, as on the live server), not by id; every fetch checks the whole table.
 *
 * `php artisan survey:sync` fetches every table; the scheduler runs it every
 * AUTO_MINUTES (routes/console.php). Sync Data shows the counts and runs
 * fetches by hand.
 */
final class SurveySync
{
    public const CONNECTION = 'mysql_live';

    /** Tables copied from the live server: name => label. */
    public const TABLES = [
        'localarea' => 'Local areas',
        'household_listing' => 'Household listing',
        'f11' => 'F11 household members',
        'f72' => 'F72 food recall lines',
        'f72_interview_status' => 'F72 interview status',
    ];

    /** Tables only counted: name => what they are. sws_data goes the other way (Transmit, in SWS Upload Data). */
    public const COUNTED = [
        'sws_data' => 'SWS data',
    ];

    /** Each table's unique key, as on the live server - how a live row finds its local copy. */
    public const KEYS = [
        'localarea' => ['province', 'municipality', 'barangay'],
        'household_listing' => Survey::HOUSEHOLD_KEYS,
        'f11' => [...Survey::HOUSEHOLD_KEYS, 'member_no'],
        'f72' => [...Survey::HOUSEHOLD_KEYS, 'member_no', 'foodrecall_day', 'lane_no'],
        'f72_interview_status' => [...Survey::HOUSEHOLD_KEYS, 'member_no', 'foodrecall_day'],
    ];

    public const AUTO_MINUTES = 10;

    /** How long a fetch of one table may hold its lock. */
    private const LOCK_SECONDS = 600;

    /** Bind parameters per insert: SQLite allows 32,766, MySQL 65,535. */
    private const MAX_BINDINGS = 30000;

    public static function live(): ConnectionInterface
    {
        return DB::connection(self::CONNECTION);
    }

    public static function reachable(): bool
    {
        return LiveServer::reachable(self::CONNECTION);
    }

    /**
     * Every table with its local and live counts and the last fetch:
     * [table, label, fetchable, local, live, last, lastOk]. Live counts are
     * null when the server can't be reached; a table missing on either side
     * counts as null there.
     */
    public static function overview(): array
    {
        $reachable = self::reachable();
        $rows = [];

        foreach (self::TABLES + self::COUNTED as $table => $label) {
            $local = self::localName($table);
            $rows[] = [
                'table' => $table,
                'label' => $label,
                'fetchable' => isset(self::TABLES[$table]),
                'local' => $local ? self::count(Survey::db(), $local) : null,
                'live' => $reachable ? self::count(self::live(), $table) : null,
                'last' => SurveySyncRun::with('user')->where('table', $table)->latest('id')->first(),
                'lastOk' => SurveySyncRun::where('table', $table)->where('status', 'ok')->latest('id')->first(),
            ];
        }

        return ['reachable' => $reachable, 'tables' => $rows];
    }

    /** Fetch every table, one after the other. Returns the runs. */
    public static function fetchAll(?User $user = null, string $trigger = 'manual'): array
    {
        $runs = [];
        foreach (array_keys(self::TABLES) as $table) {
            $runs[$table] = self::fetch($table, $user, $trigger);
            // No point waiting on the server for each table once it's gone
            if ($runs[$table]->status === 'failed' && !self::reachable()) {
                break;
            }
        }

        return $runs;
    }

    /** Add the live rows of one table that aren't here yet. Always returns the logged run. */
    public static function fetch(string $table, ?User $user = null, string $trigger = 'manual'): SurveySyncRun
    {
        if (!isset(self::TABLES[$table])) {
            throw new \InvalidArgumentException("{$table} isn't fetched from the live server.");
        }

        $started = microtime(true);
        $run = new SurveySyncRun([
            'table' => $table, 'user_id' => $user?->id, 'trigger' => $trigger, 'mode' => 'add-only',
            'status' => 'failed', 'fetched' => 0, 'retained' => 0,
        ]);

        $lock = Cache::lock("survey-sync.{$table}", self::LOCK_SECONDS);
        if (!$lock->get()) {
            $run->message = 'Another fetch of this table is still running.';
            $run->save();

            return $run;
        }

        try {
            if (!self::reachable()) {
                throw new \RuntimeException("The live server can't be reached.");
            }
            if (!self::live()->getSchemaBuilder()->hasTable($table)) {
                throw new \RuntimeException("The live server has no {$table} table.");
            }

            self::prepareLocal($table);
            $run->fill(self::addMissing($table));
            $run->status = 'ok';
            self::forgetCaches();
        } catch (\Throwable $e) {
            $run->message = mb_substr($e->getMessage(), 0, 1000);
            Log::warning("Survey sync of {$table} failed: " . $e->getMessage());
        } finally {
            $lock->release();
        }

        try {
            $run->local_count = self::count(Survey::db(), self::localName($table) ?? $table);
        } catch (\Throwable) {
            // The count is a nicety; the run is still logged
        }
        $run->duration_ms = (int) round((microtime(true) - $started) * 1000);
        $run->save();

        return $run;
    }

    /** When the auto-fetch last ran (any table), or null. */
    public static function lastAuto(): ?SurveySyncRun
    {
        return SurveySyncRun::where('trigger', 'auto')->latest('id')->first();
    }

    // ----- Internals -----

    /** The local table exists and has the live layout. */
    private static function prepareLocal(string $table): void
    {
        Survey::flush();
        if (self::localName($table) === null) {
            Survey::install();
        } elseif (Survey::differs($table)) {
            Survey::rebuild($table);
        }
        Survey::flush();
    }

    /** Insert the live rows whose key isn't here yet - nothing is updated or removed. Returns the run's figures. */
    private static function addMissing(string $table): array
    {
        $live = self::live();
        $local = Survey::db();
        $target = self::localName($table);
        $key = self::KEYS[$table];

        // The local id is the local table's own: rows are matched by key
        $columns = array_values(array_diff(array_intersect(
            $live->getSchemaBuilder()->getColumnListing($table),
            Survey::columns($target),
        ), ['id']));
        if (array_diff($key, $columns) !== []) {
            throw new \RuntimeException("{$table} is missing part of its key (" . implode(', ', array_diff($key, $columns)) . ').');
        }

        // Every row's key on both sides: live id => key, and the local keys
        $keyOf = fn ($r) => implode("\x1F", array_map(fn ($c) => (string) ($r->{$c} ?? ''), $key));
        $liveKeys = $live->table($table)->select(['id', ...$key])->get()->mapWithKeys(fn ($r) => [(int) $r->id => $keyOf($r)])->all();
        $localKeys = array_flip($local->table($target)->select($key)->get()->map($keyOf)->all());

        $missing = array_keys(array_filter($liveKeys, fn ($k) => !isset($localKeys[$k])));
        $chunk = max(50, intdiv(self::MAX_BINDINGS, count($columns)));
        $added = 0;
        foreach (array_chunk($missing, $chunk) as $ids) {
            $rows = $live->table($table)->select($columns)->whereIn('id', $ids)->orderBy('id')->get()
                ->map(function ($r) use ($columns, $key) {
                    $row = array_intersect_key((array) $r, array_flip($columns));
                    // A key part sent as NULL is '' here, so it matches next time
                    foreach ($key as $c) {
                        $row[$c] ??= '';
                    }

                    return $row;
                })->all();
            // insertOrIgnore: a row added here meanwhile (another fetch) is left as it is
            $added += $local->table($target)->insertOrIgnore($rows);
        }

        // Rows here that the live server no longer has: kept, and counted
        $onLive = array_flip($liveKeys);
        $retained = count(array_filter(array_keys($localKeys), fn ($k) => !isset($onLive[$k])));

        return [
            'fetched' => $added,
            'retained' => $retained,
            'live_count' => count($liveKeys),
            'watermark' => in_array('date_updated', $columns, true) ? $live->table($table)->max('date_updated') : null,
        ];
    }

    private static function localName(string $table): ?string
    {
        if ($table === 'localarea') {
            return Survey::localAreaTable();
        }

        return Survey::has($table) ? $table : null;
    }

    private static function count(ConnectionInterface $db, string $table): ?int
    {
        try {
            return $db->getSchemaBuilder()->hasTable($table) ? $db->table($table)->count() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Pages cache figures from the survey tables for a few minutes - start them afresh. */
    private static function forgetCaches(): void
    {
        foreach (['survey.f11.areas', 'survey.f72.areas', 'survey.summary.v2'] as $key) {
            Cache::forget($key);
        }
    }
}
