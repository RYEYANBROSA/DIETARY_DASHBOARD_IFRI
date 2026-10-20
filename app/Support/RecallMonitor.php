<?php

namespace App\Support;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 24-hour food recall monitoring, read from f72_interview_status (one row per
 * recall the field researchers transmit). For each recall day:
 *
 *   completed   interview_status 1 or 2
 *   refused     interview_status 3 and above
 *   target      SWS respondents (sws_data) in the same area - the base of every rate
 *   not yet     target - completed - refused
 *
 * Areas line up as f72_interview_status province / municipality / barangay =
 * sws_data city / district / barangay. A recall sent more than once counts
 * once, by its latest update (identifiers 1-3 + sws_member_no + member_no + day).
 *
 * The table is read from RECALL_MONITOR_CONNECTION - the live server
 * (mysql_live) the tablets transmit to. Because that server can be slow or out
 * of reach, all rows are read once and kept for CACHE_SECONDS (every filter
 * then works from memory), the server is probed with a short socket check
 * before any query, and a failure is remembered for DOWN_SECONDS so the pages
 * say "can't be reached" at once instead of waiting on a connection timeout.
 * refresh() drops both.
 */
final class RecallMonitor
{
    public const TABLE = 'f72_interview_status';

    public const COMPLETED = ['1', '2'];

    /** Lowest code counted as refused. */
    public const REFUSED_FROM = 3;

    public const DAYS = ['1' => 'Day 1', '2' => 'Day 2'];

    /** How long rows read from the server are reused. */
    public const CACHE_SECONDS = 120;

    /** How long an unreachable server is assumed to stay down before trying again. */
    public const DOWN_SECONDS = 60;

    /** Socket check before querying a remote server, in seconds. */
    private const PROBE_SECONDS = 3;

    public static function connectionName(): string
    {
        return (string) config('database.recall_monitor_connection', 'survey');
    }

    public static function db(): ConnectionInterface
    {
        return DB::connection(self::connectionName());
    }

    /** Whether the recall table can be read right now. */
    public static function available(): bool
    {
        return self::state() === 'ok';
    }

    /** ok | missing (no table yet) | unreachable (server down or out of reach). */
    public static function state(): string
    {
        if (Cache::get(self::key('down'))) {
            return 'unreachable';
        }
        if (!self::reachable()) {
            self::markDown('no answer on the database port');

            return 'unreachable';
        }

        try {
            $has = Cache::remember(self::key('table'), self::CACHE_SECONDS, fn () => self::db()->getSchemaBuilder()->hasTable(self::TABLE));
        } catch (\Throwable $e) {
            self::markDown($e->getMessage());

            return 'unreachable';
        }

        return $has ? 'ok' : 'missing';
    }

    /** When the rows in use were read from the server, or null before the first read. */
    public static function fetchedAt(): ?Carbon
    {
        $at = Cache::get(self::key('rows'))['at'] ?? null;

        return $at ? Carbon::parse($at) : null;
    }

    /** Forget the cached rows and any "server down" note, so the next read goes to the server. */
    public static function refresh(): void
    {
        foreach (['rows', 'table', 'down'] as $what) {
            Cache::forget(self::key($what));
        }
    }

    /** Recalls received per date transmitted, newest first: [day, recalls, researchers, last]. */
    public static function deliveries(int $limit): array
    {
        return collect(self::rows())->filter(fn ($r) => $r['date_transmitted'])
            ->groupBy(fn ($r) => substr($r['date_transmitted'], 0, 10))
            ->sortKeysDesc()->take($limit)
            ->map(fn ($set, $day) => [
                'day' => $day,
                'recalls' => $set->count(),
                'researchers' => $set->pluck('researcher')->filter()->unique()->count(),
                'last' => $set->max('date_transmitted'),
            ])->values()->all();
    }

    /** Distinct provinces in the recalls (upper case). */
    public static function provinces(): Collection
    {
        return collect(self::rows())->pluck('province')->filter()->map(fn ($p) => strtoupper(trim($p)))->unique()->values();
    }

    /** completed | refused | other, from an interview_status value. */
    public static function outcome(mixed $status): string
    {
        $status = trim((string) $status);

        if (in_array($status, self::COMPLETED, true)) {
            return 'completed';
        }
        if (is_numeric($status) && (int) $status >= self::REFUSED_FROM) {
            return 'refused';
        }

        return 'other';
    }

    /** Filter choices: areas (from both the recalls and SWS data), researchers and the date range. */
    public static function options(array $f): array
    {
        $rows = collect(self::rows());
        $recalls = $rows->map(fn ($r) => [strtoupper((string) $r['province']), strtoupper((string) $r['municipality']), strtoupper((string) $r['barangay'])]);
        $targets = SwsStore::table()->select('city', 'district', 'barangay')->distinct()->get()
            ->map(fn ($r) => [strtoupper((string) $r->city), strtoupper((string) $r->district), strtoupper((string) $r->barangay)]);
        $areas = $recalls->merge($targets)->unique(fn ($a) => implode('|', $a));

        $dates = $rows->pluck('date_transmitted')->filter();

        return [
            'provinces' => $areas->pluck(0)->filter()->unique()->sort()->values(),
            'municipalities' => $areas->where(0, $f['province'] ?? '')->pluck(1)->filter()->unique()->sort()->values(),
            'barangays' => $areas->where(0, $f['province'] ?? '')->where(1, $f['municipality'] ?? '')->pluck(2)->filter()->unique()->sort()->values(),
            'researchers' => $rows->pluck('researcher')->filter(fn ($r) => trim((string) $r) !== '')->unique()->sort()->values(),
            'firstDate' => $dates->isNotEmpty() ? substr($dates->min(), 0, 10) : null,
            'lastDate' => $dates->isNotEmpty() ? substr($dates->max(), 0, 10) : null,
        ];
    }

    /**
     * Everything the monitoring tab shows, for filters
     * [from, to, province, municipality, barangay, researcher, day].
     */
    public static function report(array $f): array
    {
        $recalls = self::recalls($f);
        $days = ($f['day'] ?? '') !== '' ? [$f['day']] : array_keys(self::DAYS);

        $target = self::target($f);
        $byDay = [];
        foreach ($days as $day) {
            $set = $recalls->where('day', $day);
            $completed = $set->where('outcome', 'completed')->count();
            $refused = $set->where('outcome', 'refused')->count();
            $byDay[$day] = [
                'label' => self::DAYS[$day],
                'completed' => $completed,
                'refused' => $refused,
                'other' => $set->where('outcome', 'other')->count(),
                'notYet' => max(0, $target - $completed - $refused),
                'completion' => self::rate($completed, $target),
                'refusal' => self::rate($refused, $target),
            ];
        }

        // Day 2 follow-up: of those who completed Day 1, how many have completed Day 2
        $doneDay1 = $recalls->where('day', '1')->where('outcome', 'completed')->pluck('member')->flip();
        $doneBoth = $recalls->where('day', '2')->where('outcome', 'completed')->filter(fn ($r) => isset($doneDay1[$r['member']]))->count();

        return [
            'target' => $target,
            'recalls' => $recalls->count(),
            'days' => $byDay,
            'followUp' => ['done' => $doneBoth, 'of' => $doneDay1->count(), 'rate' => self::rate($doneBoth, $doneDay1->count())],
            'household' => self::householdSurvey($f),
            'researchers' => self::byResearcher($recalls),
            'areas' => self::byArea($f, $recalls),
            'areaLevel' => self::nextLevel($f),
            'trend' => self::trend($recalls),
        ];
    }

    /** Household completion: both days completed, one day completed, any refusal, no completed recall yet. */
    public const HOUSEHOLD_STATUSES = [
        'complete' => 'Completed',
        'partial' => '1 of 2 days',
        'refused' => 'Refused',
        'notyet' => 'Not yet',
    ];

    /**
     * One row per household that has transmitted a status to
     * f72_interview_status (identifiers 1-3 + SWS member no.), with its latest
     * Day 1 and Day 2 recall: status, outcome, date transmitted and researcher,
     * and the household's completion (HOUSEHOLD_STATUSES). The area is the
     * recall's own; the name comes from the matching SWS record, as the recall
     * table has none. Every filter applies; $search matches the identifiers,
     * SWS member no. and name, and $status narrows to one completion.
     */
    public static function households(array $f, string $search = '', string $status = ''): Collection
    {
        $households = [];
        foreach (self::recalls($f) as $r) {
            $households[$r['sws']]['ids'] = $r['ids'];
            $households[$r['sws']]['area'] = $r['area'];
            $households[$r['sws']]['days'][$r['day']] = $r;
        }
        if ($households === []) {
            return collect();
        }

        $names = SwsStore::table()->get(['identifier1', 'identifier2', 'identifier3', 'sws_member_no', 'lastname', 'firstname'])
            ->keyBy(fn ($r) => SwsRecord::keyOf($r));
        $search = strtoupper(trim($search));

        return collect($households)
            ->map(function ($hh, $key) use ($names) {
                $sws = $names[$key] ?? null;
                $row = [
                    'key' => $key,
                    'city' => $hh['area'][0], 'district' => $hh['area'][1], 'barangay' => $hh['area'][2],
                    'identifier1' => $hh['ids'][0], 'identifier2' => $hh['ids'][1], 'identifier3' => $hh['ids'][2], 'sws_member_no' => $hh['ids'][3],
                    'lastname' => (string) ($sws->lastname ?? ''), 'firstname' => (string) ($sws->firstname ?? ''),
                    'inSws' => $sws !== null,
                ];
                foreach (self::DAYS as $day => $label) {
                    $r = $hh['days'][$day] ?? null;
                    $row['days'][$day] = $r ? ['status' => $r['status'], 'outcome' => $r['outcome'], 'date' => $r['date'], 'researcher' => $r['researcher']] : null;
                }
                $row['completion'] = self::householdStatus($row['days']);

                return $row;
            })
            ->when($search !== '', fn ($c) => $c->filter(fn ($row) => str_contains(strtoupper(implode(' ', [
                $row['identifier1'], $row['identifier2'], $row['identifier3'], $row['sws_member_no'], $row['lastname'], $row['firstname'],
            ])), $search)))
            ->when(isset(self::HOUSEHOLD_STATUSES[$status]), fn ($c) => $c->where('completion', $status))
            ->sortBy(fn ($row) => implode('|', [$row['city'], $row['district'], $row['barangay'], $row['identifier1'], $row['identifier2'], $row['identifier3'], $row['sws_member_no']]))
            ->values();
    }

    /** complete | partial | refused | notyet, from a household's Day 1 / Day 2 recalls. */
    private static function householdStatus(array $days): string
    {
        $outcomes = array_map(fn ($d) => $d['outcome'] ?? null, $days);

        if (in_array('refused', $outcomes, true)) {
            return 'refused';
        }
        if (($outcomes['1'] ?? null) === 'completed' && ($outcomes['2'] ?? null) === 'completed') {
            return 'complete';
        }

        return in_array('completed', $outcomes, true) ? 'partial' : 'notyet';
    }

    /**
     * Each SWS respondent's latest outcome per recall day, keyed like
     * SwsRecord::keyOf(): ['QC001|QC001|QC001|SWS_001' => ['1' => 'completed', '2' => 'refused']].
     */
    public static function outcomesBySwsKey(array $f): array
    {
        $out = [];
        foreach (self::recalls($f) as $r) {
            $out[$r['sws']][$r['day']] = $r['outcome'];
        }

        return $out;
    }

    // ----- Pieces -----

    /**
     * Every recall row, read from the server at most once per CACHE_SECONDS.
     * A failed read marks the server down for DOWN_SECONDS and is rethrown.
     */
    private static function rows(): array
    {
        $cached = Cache::get(self::key('rows'));
        if ($cached !== null) {
            return $cached['rows'];
        }

        try {
            $rows = self::db()->table(self::TABLE)->orderBy('id')
                ->get(['province', 'municipality', 'barangay', 'identifier1', 'identifier2', 'identifier3', 'sws_member_no', 'member_no', 'foodrecall_day', 'interview_status', 'researcher', 'date_transmitted', 'date_updated'])
                ->map(fn ($r) => (array) $r)->all();
        } catch (\Throwable $e) {
            self::markDown($e->getMessage());
            throw $e;
        }

        Cache::put(self::key('rows'), ['at' => now()->toIso8601String(), 'rows' => $rows], self::CACHE_SECONDS);

        return $rows;
    }

    /** Filtered recalls, one per member and day (latest update wins), as plain arrays. */
    private static function recalls(array $f): Collection
    {
        $match = fn ($value, $wanted) => strtoupper(trim((string) $value)) === strtoupper(trim((string) $wanted));

        return collect(self::rows())
            ->filter(fn ($r) => (($f['from'] ?? '') === '' || (string) $r['date_transmitted'] >= $f['from'] . ' 00:00:00')
                && (($f['to'] ?? '') === '' || ((string) $r['date_transmitted'] !== '' && (string) $r['date_transmitted'] <= $f['to'] . ' 23:59:59'))
                && (($f['researcher'] ?? '') === '' || (string) $r['researcher'] === $f['researcher'])
                && (($f['province'] ?? '') === '' || $match($r['province'], $f['province']))
                && (($f['municipality'] ?? '') === '' || $match($r['municipality'], $f['municipality']))
                && (($f['barangay'] ?? '') === '' || $match($r['barangay'], $f['barangay'])))
            ->map(fn ($r) => (object) $r)
            ->map(fn ($r) => [
                'member' => implode('|', [$r->identifier1, $r->identifier2, $r->identifier3, $r->sws_member_no, $r->member_no]),
                // Same shape as SwsRecord::keyOf(), to find the SWS respondent the recall belongs to
                'sws' => implode('|', [(string) $r->identifier1, (string) $r->identifier2, (string) $r->identifier3, (string) $r->sws_member_no]),
                'ids' => [(string) $r->identifier1, (string) $r->identifier2, (string) $r->identifier3, (string) $r->sws_member_no],
                'day' => trim((string) $r->foodrecall_day),
                'status' => trim((string) $r->interview_status),
                'outcome' => self::outcome($r->interview_status),
                'researcher' => (string) $r->researcher,
                'area' => [strtoupper((string) $r->province), strtoupper((string) $r->municipality), strtoupper((string) $r->barangay)],
                'date' => $r->date_transmitted ? substr($r->date_transmitted, 0, 10) : null,
                'updated' => (string) ($r->date_updated ?? $r->date_transmitted),
            ])
            ->filter(fn ($r) => isset(self::DAYS[$r['day']]))
            ->sortBy('updated')
            ->keyBy(fn ($r) => $r['member'] . '#' . $r['day'])
            ->values();
    }

    /** Cache key for this connection's rows / table check / down note. */
    private static function key(string $what): string
    {
        return 'recall_monitor.' . self::connectionName() . '.' . $what;
    }

    private static function markDown(string $reason): void
    {
        Cache::put(self::key('down'), true, self::DOWN_SECONDS);
        Log::warning('24-hr recall monitoring: ' . self::connectionName() . ' unreachable - ' . $reason);
    }

    /**
     * A short socket check on the server's port before querying it: a server
     * out of reach otherwise holds the page for the full connection timeout.
     * Local and SQLite connections always pass.
     */
    private static function reachable(): bool
    {
        $config = config('database.connections.' . self::connectionName(), []);
        if (!in_array($config['driver'] ?? '', ['mysql', 'mariadb'], true) || !empty($config['unix_socket'])) {
            return true;
        }

        $host = (string) ($config['host'] ?? '127.0.0.1');
        if (in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            return true;
        }

        $socket = @fsockopen($host, (int) ($config['port'] ?? 3306), $errno, $error, self::PROBE_SECONDS);
        if ($socket === false) {
            return false;
        }
        fclose($socket);

        return true;
    }

    /** SWS respondents in the filtered area - the base of every rate. */
    private static function target(array $f): int
    {
        $query = SwsStore::table();
        self::inArea($query, $f, ['city', 'district', 'barangay']);

        return $query->count();
    }

    private static function inArea(Builder $query, array $f, array $columns): void
    {
        foreach (['province', 'municipality', 'barangay'] as $i => $key) {
            if (($f[$key] ?? '') !== '') {
                $query->whereRaw('UPPER(' . $columns[$i] . ') = ?', [strtoupper($f[$key])]);
            }
        }
    }

    /** The household survey (household_listing) for the same area: completion 1-2, refusal 4. */
    private static function householdSurvey(array $f): ?array
    {
        if (!Survey::has('household_listing')) {
            return null;
        }

        $query = Survey::db()->table('household_listing');
        self::inArea($query, $f, ['province', 'municipality', 'barangay']);
        $total = (clone $query)->count();
        if ($total === 0) {
            return null;
        }

        $covered = (clone $query)->whereIn('interview_status', SurveyStats::COVERED)->count();
        $refused = (clone $query)->where('interview_status', '4')->count();

        return ['households' => $total, 'completion' => self::rate($covered, $total), 'refusal' => self::rate($refused, $total), 'covered' => $covered, 'refused' => $refused];
    }

    private static function byResearcher(Collection $recalls): array
    {
        return $recalls->groupBy('researcher')->map(function ($set, $name) {
            $row = ['name' => $name ?: '(none)'];
            foreach (self::DAYS as $day => $label) {
                $d = $set->where('day', $day);
                $completed = $d->where('outcome', 'completed')->count();
                $refused = $d->where('outcome', 'refused')->count();
                $row[$day] = ['recalls' => $d->count(), 'completed' => $completed, 'refused' => $refused, 'rate' => self::rate($completed, $d->count())];
            }
            $row['total'] = $set->count();

            return $row;
        })->sortByDesc('total')->values()->all();
    }

    /** Province / district / barangay one level below the current filter. */
    private static function nextLevel(array $f): int
    {
        return ($f['province'] ?? '') === '' ? 0 : (($f['municipality'] ?? '') === '' ? 1 : 2);
    }

    private static function byArea(array $f, Collection $recalls): array
    {
        $level = self::nextLevel($f);
        $targetColumn = ['city', 'district', 'barangay'][$level];

        $targets = SwsStore::table();
        self::inArea($targets, $f, ['city', 'district', 'barangay']);
        $targets = $targets->selectRaw("UPPER({$targetColumn}) as area, COUNT(*) as n")->groupBy(DB::raw("UPPER({$targetColumn})"))->pluck('n', 'area');

        $names = $targets->keys()->merge($recalls->map(fn ($r) => $r['area'][$level])->unique())->filter()->unique()->sort()->values();

        return $names->map(function ($name) use ($targets, $recalls, $level) {
            $set = $recalls->filter(fn ($r) => $r['area'][$level] === $name);
            $target = (int) ($targets[$name] ?? 0);
            $row = ['name' => $name, 'target' => $target];
            foreach (self::DAYS as $day => $label) {
                $d = $set->where('day', $day);
                $row[$day] = [
                    'completed' => $d->where('outcome', 'completed')->count(),
                    'refused' => $d->where('outcome', 'refused')->count(),
                    'completion' => self::rate($d->where('outcome', 'completed')->count(), $target),
                    'refusal' => self::rate($d->where('outcome', 'refused')->count(), $target),
                ];
            }

            return $row;
        })->all();
    }

    /** Completed and refused per date transmitted and recall day. */
    private static function trend(Collection $recalls): array
    {
        return $recalls->whereNotNull('date')->groupBy('date')->sortKeys()->map(fn ($set, $date) => [
            'date' => $date,
            'completed' => $set->where('outcome', 'completed')->count(),
            'refused' => $set->where('outcome', 'refused')->count(),
            '1' => $set->where('day', '1')->count(),
            '2' => $set->where('day', '2')->count(),
        ])->values()->all();
    }

    /** Percentage to one decimal; null when there is nothing to divide by. */
    private static function rate(int $part, int $whole): ?float
    {
        return $whole > 0 ? round($part / $whole * 100, 1) : null;
    }
}
