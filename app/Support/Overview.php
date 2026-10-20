<?php

namespace App\Support;

use App\Models\F11Activity;
use App\Models\SwsActivity;
use Illuminate\Support\Collection;

/**
 * The Dashboard overview (map-first): the SWS households on a map coloured by
 * their 24-hr recall status, the key figures beside it, the latest activity,
 * researcher completion, and what people ate. One optional filter: province
 * (household_listing / f72 province = sws_data city = recall province).
 */
final class Overview
{
    /** Most households drawn on the overview map. */
    public const MAP_LIMIT = 5000;

    /** Map status => [label, colour], in legend order. */
    public const STATUSES = [
        // Map pins: green = completed (deeper for both days), red = refused, gray = not yet
        'both' => ['Day 1 and 2 completed', '#15803D'],
        'day1' => ['Day 1 completed', '#4ADE80'],
        'refused' => ['Refused', '#DC2626'],
        'notyet' => ['Not yet visited', '#9CA3AF'],
    ];

    public static function build(string $province): array
    {
        $f = ['from' => '', 'to' => '', 'province' => $province, 'municipality' => '', 'barangay' => '', 'researcher' => '', 'day' => ''];
        $upper = strtoupper($province);

        $households = Survey::has('household_listing')
            ? SurveyStats::householdStatus(Survey::db()->table('household_listing')->when($province !== '', fn ($q) => $q->whereRaw('UPPER(province) = ?', [$upper])))
            : null;

        // The recalls come from the live server; if it can't be reached the rest still shows
        $recallState = RecallMonitor::state();
        $recallReady = $recallState === 'ok';
        $recall = $recallReady ? RecallMonitor::report($f) : null;

        $sws = SwsStore::table()->when($province !== '', fn ($q) => $q->whereRaw('UPPER(city) = ?', [$upper]));
        $swsTotal = (clone $sws)->count();
        $swsSent = (clone $sws)->where('transmitted', 'yes')->count();

        $lines = Survey::db()->table('f72')->when($province !== '', fn ($q) => $q->whereRaw('UPPER(province) = ?', [$upper]));
        $lineCount = (clone $lines)->count();

        return [
            'households' => $households,
            'recall' => $recall,
            'recallState' => $recallState,
            'recallFetchedAt' => $recallReady ? RecallMonitor::fetchedAt() : null,
            'sws' => ['total' => $swsTotal, 'sent' => $swsSent],
            'map' => self::mapPoints($sws, $recallReady ? RecallMonitor::outcomesBySwsKey($f) : []),
            'activity' => self::activity($recallReady),
            'food' => [
                'lines' => $lineCount,
                'meals' => SurveyStats::meals($lines, $lineCount),
                'foods' => SurveyStats::topFoods($lines, 5),
            ],
        ];
    }

    /** Provinces to filter by: wherever there are households, SWS respondents or recalls. */
    public static function provinces(): Collection
    {
        $names = collect();
        if (Survey::has('household_listing')) {
            $names = $names->merge(Survey::db()->table('household_listing')->distinct()->pluck('province'));
        }
        $names = $names->merge(SwsStore::table()->distinct()->pluck('city'));
        if (RecallMonitor::available()) {
            $names = $names->merge(RecallMonitor::provinces());
        }

        return $names->filter()->map(fn ($n) => strtoupper(trim($n)))->unique()->sort()->values();
    }

    /**
     * SWS households with coordinates, as [id, lat, lng, member no., name, barangay, status],
     * plus a count per status for the legend.
     */
    private static function mapPoints($sws, array $outcomes): array
    {
        $points = [];
        $counts = array_fill_keys(array_keys(self::STATUSES), 0);

        (clone $sws)->whereNotNull('latitude')->where('latitude', '!=', '')->whereNotNull('longitude')->where('longitude', '!=', '')
            ->orderBy('id')->limit(self::MAP_LIMIT)
            ->get(['id', 'latitude', 'longitude', 'sws_member_no', 'lastname', 'firstname', 'barangay', 'district', 'identifier1', 'identifier2', 'identifier3'])
            ->each(function ($r) use (&$points, &$counts, $outcomes) {
                if (!is_numeric($r->latitude) || !is_numeric($r->longitude)) {
                    return;
                }
                $o = $outcomes[SwsRecord::keyOf($r)] ?? [];
                $status = match (true) {
                    ($o['1'] ?? null) === 'completed' && ($o['2'] ?? null) === 'completed' => 'both',
                    in_array('refused', $o, true) => 'refused',
                    ($o['1'] ?? null) === 'completed' => 'day1',
                    default => 'notyet',
                };
                $counts[$status]++;
                $points[] = [$r->id, round((float) $r->latitude, 7), round((float) $r->longitude, 7), $r->sws_member_no,
                    trim("{$r->lastname}, {$r->firstname}", ', '), trim("{$r->barangay}, {$r->district}", ', '), $status];
            });

        return ['points' => $points, 'counts' => $counts];
    }

    /** The latest F11 and SWS actions and recall deliveries, newest first. */
    private static function activity(bool $recallReady): array
    {
        $items = collect();
        $label = fn ($a) => ['imported' => 'Uploaded', 'created' => 'Added', 'updated' => 'Edited', 'transmitted' => 'Transmitted'][$a] ?? ucfirst($a);

        foreach (F11Activity::with('user')->latest('id')->limit(6)->get() as $a) {
            $items->push(['when' => $a->created_at, 'what' => $label($a->action) . ' · F11', 'detail' => $a->action === 'imported' ? "{$a->subject} ({$a->inserted} new, {$a->updated} updated)" : $a->subject, 'who' => $a->user?->name]);
        }
        foreach (SwsActivity::with('user')->latest('id')->limit(6)->get() as $a) {
            $items->push(['when' => $a->created_at, 'what' => $label($a->action) . ' · SWS', 'detail' => in_array($a->action, ['imported', 'transmitted'], true) ? "{$a->subject} ({$a->inserted} new, {$a->updated} updated)" : $a->subject, 'who' => $a->user?->name]);
        }
        if ($recallReady) {
            foreach (RecallMonitor::deliveries(3) as $d) {
                $items->push(['when' => \Illuminate\Support\Carbon::parse($d['last']), 'what' => 'Received · 24-hr recall', 'detail' => "{$d['recalls']} recall(s) from {$d['researchers']} researcher(s)", 'who' => null]);
            }
        }

        return $items->sortByDesc('when')->take(6)->values()->all();
    }
}
