<?php

namespace App\Support;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Read-only figures from the survey database, as the old DDCS dashboard showed
 * them. summary() is cached for ten minutes; if the survey server can't be
 * reached it returns null and the pages say so instead of failing.
 */
final class SurveyStats
{
    public const MEALS = [
        1 => 'Breakfast',
        2 => 'AM snack',
        3 => 'Lunch',
        4 => 'PM snack',
        5 => 'Supper',
        6 => 'Late PM snack',
        7 => 'Supplements',
    ];

    /** Food item values that are placeholders, not foods. */
    public const NOT_FOODS = ['', 'FOR DELETION'];

    /** Household statuses that count as covered. */
    public const COVERED = ['1', '2'];

    public static function db(): ConnectionInterface
    {
        return Survey::db();
    }

    public static function summary(): ?array
    {
        try {
            return Cache::remember('survey.summary.v2', now()->addMinutes(10), function () {
                $db = self::db();
                $households = self::householdStatus($db->table('household_listing'));
                $foodLines = $db->table('f72')->count();
                $localArea = Survey::localAreaTable();

                return [
                    ...$households,
                    'barangays' => self::distinctCount($db->table('household_listing'), ['province', 'municipality', 'barangay']),
                    'local_areas' => $localArea ? $db->table($localArea)->count() : 0,
                    'members' => $db->table('f11')->count(),
                    'food_lines' => $foodLines,
                    'recalled_members' => self::distinctCount($db->table('f72'), [...Survey::HOUSEHOLD_KEYS, 'member_no']),
                    'researchers' => self::distinctCount($db->table('f72')->whereNotNull('researcher')->where('researcher', '!=', ''), ['researcher']),
                    'transmissions' => Survey::has('transmission_log') ? $db->table('transmission_log')->count() : null,
                    'daily_researchers' => self::dailyResearchers(),
                    'meals' => self::meals($db->table('f72'), $foodLines),
                    'weight_methods' => self::weightMethods($db->table('f72')),
                    'municipalities' => self::ranked($db->table('household_listing'), 'municipality', 10),
                    'provinces' => self::ranked($db->table('household_listing'), 'province', 8),
                    'foods' => self::topFoods($db->table('f72'), 5),
                ];
            });
        } catch (\Throwable $e) {
            Log::warning('Survey database unavailable: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Household counts by interview status for a household_listing query:
     * totals, coverage, and one row per status code (plus "no status").
     */
    public static function householdStatus(Builder $query): array
    {
        $counts = (clone $query)
            ->select('interview_status', DB::raw('COUNT(*) as total'))
            ->groupBy('interview_status')
            ->pluck('total', 'interview_status');

        $byCode = [];
        $unknown = 0;
        foreach ($counts as $code => $total) {
            $code = trim((string) $code);
            if (isset(Survey::HOUSEHOLD_STATUS[$code])) {
                $byCode[$code] = ($byCode[$code] ?? 0) + (int) $total;
            } else {
                $unknown += (int) $total;
            }
        }

        $households = array_sum($byCode) + $unknown;
        $covered = array_sum(array_intersect_key($byCode, array_flip(self::COVERED)));
        $pct = fn ($n) => $households > 0 ? round($n / $households * 100, 1) : 0;

        $statuses = [];
        foreach (Survey::HOUSEHOLD_STATUS as $code => $label) {
            $statuses[] = ['code' => $code, 'label' => $label, 'count' => $byCode[$code] ?? 0, 'pct' => $pct($byCode[$code] ?? 0)];
        }
        $statuses[] = ['code' => '', 'label' => 'No status', 'count' => $unknown, 'pct' => $pct($unknown)];

        return [
            'households' => $households,
            'covered' => $covered,
            'coverage' => $pct($covered),
            'not_covered' => $households - $covered,
            'no_status' => $unknown,
            'statuses' => $statuses,
        ];
    }

    /** Share of food lines per meal, from an f72 query (filtered or not). */
    public static function meals(Builder $query, ?int $total = null): array
    {
        $counts = (clone $query)
            ->select('mealcode', DB::raw('COUNT(*) as total'))
            ->groupBy('mealcode')
            ->pluck('total', 'mealcode');

        $total ??= (int) $counts->sum();
        $max = max(1, (int) $counts->max());

        $meals = [];
        foreach (self::MEALS as $code => $label) {
            $count = (int) ($counts[$code] ?? 0);
            $meals[] = [
                'label' => $label,
                'count' => $count,
                'pct' => $total > 0 ? round($count / $total * 100, 1) : 0,
                'width' => round($count / $max * 100, 1),
            ];
        }

        return $meals;
    }

    /** Share of food lines per weighing method. */
    public static function weightMethods(Builder $query): array
    {
        $counts = (clone $query)
            ->select('weight_method', DB::raw('COUNT(*) as total'))
            ->whereNotNull('weight_method')
            ->groupBy('weight_method')
            ->pluck('total', 'weight_method');

        $known = collect(Survey::WEIGHT_METHODS)->map(fn ($label, $code) => (int) ($counts[$code] ?? 0));
        $total = max(1, $known->sum());

        return $known->map(fn ($count, $code) => [
            'label' => Survey::WEIGHT_METHODS[$code],
            'count' => $count,
            'pct' => round($count / $total * 100, 1),
        ])->values()->all();
    }

    public static function topFoods(Builder $query, int $limit): array
    {
        return (clone $query)
            ->select('fooditem', DB::raw('COUNT(*) as total'))
            ->whereNotNull('fooditem')
            ->whereNotIn('fooditem', self::NOT_FOODS)
            ->groupBy('fooditem')
            ->orderByDesc('total')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => ['label' => $row->fooditem, 'count' => (int) $row->total])
            ->all();
    }

    /** Rows per value of $column, largest first. */
    public static function ranked(Builder $query, string $column, int $limit): array
    {
        return (clone $query)
            ->select($column, DB::raw('COUNT(*) as total'))
            ->groupBy($column)
            ->orderByDesc('total')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => ['label' => $row->{$column} ?: 'Unspecified', 'count' => (int) $row->total])
            ->all();
    }

    /** Distinct combinations of $columns in a query - portable across drivers. */
    public static function distinctCount(Builder $query, array $columns): int
    {
        return self::db()->query()->fromSub((clone $query)->select($columns)->distinct(), 'd')->count();
    }

    /** Average number of researchers transmitting per day (transmission_log), if that table exists. */
    private static function dailyResearchers(): ?float
    {
        if (!Survey::has('transmission_log')) {
            return null;
        }

        $perDay = self::db()->table('transmission_log')
            ->whereNotNull('transmitted_date')
            ->select('transmitted_date', DB::raw('COUNT(DISTINCT transmitted_by) as researchers'))
            ->groupBy('transmitted_date')
            ->pluck('researchers');

        return $perDay->isEmpty() ? 0.0 : round($perDay->avg(), 1);
    }
}
