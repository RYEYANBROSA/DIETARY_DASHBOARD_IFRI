<?php

namespace App\Livewire;

use App\Support\Survey;
use App\Support\SurveyStats;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * F72 food recall, read from the survey database's f72 table the way the old
 * DDCS dashboard's area view showed it: coverage per barangay, the households
 * of an area with their day 1 / day 2 recalls, the members recalled, and the
 * food lines themselves - plus the CSV / ZIP downloads for the current area.
 */
#[Title('F72 Food Recall')]
class F72Data extends Component
{
    use WithPagination;

    /** areas | households | members | lines */
    #[Url(except: 'areas')]
    public string $tab = 'areas';

    #[Url(except: '')]
    public string $province = '';

    #[Url(except: '')]
    public string $municipality = '';

    #[Url(except: '')]
    public string $barangay = '';

    #[Url(except: '')]
    public string $day = '';

    #[Url(except: '')]
    public string $search = '';

    public function showTab(string $tab): void
    {
        $this->tab = in_array($tab, ['areas', 'households', 'members', 'lines'], true) ? $tab : 'areas';
        $this->search = '';
        $this->resetPage();
    }

    public function updated(string $property): void
    {
        // Narrowing a level clears the levels under it
        if ($property === 'province') {
            $this->municipality = '';
            $this->barangay = '';
        }
        if ($property === 'municipality') {
            $this->barangay = '';
        }

        $this->resetPage();
    }

    /** Drill into one barangay from the coverage table. */
    public function openArea(string $province, string $municipality, string $barangay): void
    {
        $this->province = $province;
        $this->municipality = $municipality;
        $this->barangay = $barangay;
        $this->tab = 'households';
        $this->search = '';
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('province', 'municipality', 'barangay', 'day', 'search');
        $this->resetPage();
    }

    /** A survey table narrowed to the area filter; $prefix qualifies the columns. */
    private function inArea(Builder $query, string $prefix = ''): Builder
    {
        return $query
            ->when($this->province !== '', fn ($q) => $q->where($prefix . 'province', $this->province))
            ->when($this->municipality !== '', fn ($q) => $q->where($prefix . 'municipality', $this->municipality))
            ->when($this->barangay !== '', fn ($q) => $q->where($prefix . 'barangay', $this->barangay));
    }

    private function onDay(Builder $query, string $prefix = ''): Builder
    {
        return $query->when(in_array($this->day, ['1', '2'], true), fn ($q) => $q->where($prefix . 'foodrecall_day', $this->day));
    }

    private function term(): string
    {
        return '%' . $this->search . '%';
    }

    /** Coverage per barangay, from the household listing. */
    private function areaRows()
    {
        return $this->inArea(Survey::db()->table('household_listing'))
            ->select(
                'province', 'municipality', 'barangay',
                DB::raw('COUNT(*) as households'),
                DB::raw("SUM(CASE WHEN interview_status IN ('1','2') THEN 1 ELSE 0 END) as covered"),
                DB::raw("SUM(CASE WHEN interview_status = '2' THEN 1 ELSE 0 END) as incomplete"),
                DB::raw("SUM(CASE WHEN interview_status = '4' THEN 1 ELSE 0 END) as refused"),
                DB::raw("SUM(CASE WHEN interview_status = '5' THEN 1 ELSE 0 END) as not_home"),
                DB::raw("SUM(CASE WHEN interview_status IS NULL OR interview_status = '' THEN 1 ELSE 0 END) as no_status"),
            )
            ->when($this->search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('province', 'like', $this->term())
                ->orWhere('municipality', 'like', $this->term())
                ->orWhere('barangay', 'like', $this->term())))
            ->groupBy('province', 'municipality', 'barangay')
            ->orderBy('province')->orderBy('municipality')->orderBy('barangay')
            ->paginate(15);
    }

    /** Listed households with the number of members recalled on each day. */
    private function householdRows()
    {
        $recalled = fn (string $day) => function ($sub) use ($day) {
            $sub->from('f72')->selectRaw('COUNT(DISTINCT member_no)')->where('foodrecall_day', $day);
            foreach (Survey::HOUSEHOLD_KEYS as $column) {
                $sub->whereColumn("f72.{$column}", "h.{$column}");
            }
        };

        return $this->inArea(Survey::db()->table('household_listing as h'), 'h.')
            ->select('h.id', 'h.province', 'h.municipality', 'h.barangay', 'h.identifier1', 'h.identifier2', 'h.identifier3', 'h.household_head', 'h.address', 'h.mobile_no', 'h.interview_status')
            ->selectSub($recalled('1'), 'day1')
            ->selectSub($recalled('2'), 'day2')
            ->when($this->search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('h.household_head', 'like', $this->term())
                ->orWhere('h.address', 'like', $this->term())
                ->orWhere('h.mobile_no', 'like', $this->term())
                ->orWhere('h.identifier1', 'like', $this->term())
                ->orWhere('h.identifier2', 'like', $this->term())))
            ->orderBy('h.municipality')->orderBy('h.barangay')->orderBy('h.identifier1')->orderBy('h.identifier2')
            ->paginate(15);
    }

    /** One row per member per recall day, with their F11 name. */
    private function memberRows()
    {
        $query = $this->onDay($this->inArea(Survey::db()->table('f72'), 'f72.'), 'f72.')
            ->leftJoin('f11', function ($join) {
                foreach ([...Survey::HOUSEHOLD_KEYS, 'member_no'] as $column) {
                    $join->on("f11.{$column}", '=', "f72.{$column}");
                }
            })
            ->select(
                'f72.province', 'f72.municipality', 'f72.barangay',
                'f72.identifier1', 'f72.identifier2', 'f72.identifier3',
                'f72.member_no', 'f72.foodrecall_day',
                DB::raw('MAX(f11.surname) as surname'),
                DB::raw('MAX(f11.givenname) as givenname'),
                DB::raw('MAX(f11.member_category) as member_category'),
                DB::raw('MAX(f72.researcher) as researcher'),
                DB::raw('MAX(f72.interview_status) as interview_status'),
                DB::raw('MIN(f72.created_at) as recorded_at'),
                DB::raw('COUNT(*) as line_count'),
            )
            ->when($this->search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('f11.surname', 'like', $this->term())
                ->orWhere('f11.givenname', 'like', $this->term())
                ->orWhere('f72.identifier1', 'like', $this->term())
                ->orWhere('f72.researcher', 'like', $this->term())))
            ->groupBy('f72.province', 'f72.municipality', 'f72.barangay', 'f72.identifier1', 'f72.identifier2', 'f72.identifier3', 'f72.member_no', 'f72.foodrecall_day')
            ->orderByDesc('recorded_at');

        return $query->paginate(15);
    }

    private function lineRows()
    {
        return $this->onDay($this->inArea(Survey::db()->table('f72')))
            ->when($this->search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('fooditem', 'like', $this->term())
                ->orWhere('researcher', 'like', $this->term())
                ->orWhere('identifier1', 'like', $this->term())
                ->orWhere('member_no', 'like', $this->term())))
            ->select('id', 'province', 'municipality', 'barangay', 'identifier1', 'identifier2', 'identifier3', 'member_no', 'foodrecall_day', 'mealcode', 'fooditem', 'foodweight', 'weight_method', 'researcher')
            ->orderByDesc('id')
            ->paginate(15);
    }

    /** Province / municipality / barangay combinations with listings or recalls. */
    private function areas()
    {
        return Cache::remember('survey.f72.areas', now()->addMinutes(10), function () {
            $db = Survey::db();
            $union = $db->table('household_listing')->select('province', 'municipality', 'barangay')
                ->union($db->table('f72')->select('province', 'municipality', 'barangay'));

            return $db->query()->fromSub($union, 'a')
                ->whereNotNull('province')->where('province', '!=', '')
                ->orderBy('province')->orderBy('municipality')->orderBy('barangay')
                ->get()->map(fn ($row) => (array) $row)->all();
        });
    }

    public function render()
    {
        try {
            $areas = collect($this->areas());
            $lines = $this->onDay($this->inArea(Survey::db()->table('f72')));
            $total = (clone $lines)->count();

            $data = [
                'offline' => false,
                'households' => SurveyStats::householdStatus($this->inArea(Survey::db()->table('household_listing'))),
                'members' => $this->inArea(Survey::db()->table('f11'))->count(),
                'totalLines' => $total,
                'recalled' => SurveyStats::distinctCount($lines, [...Survey::HOUSEHOLD_KEYS, 'member_no', 'foodrecall_day']),
                'topFood' => SurveyStats::topFoods($lines, 1)[0] ?? null,
                'meals' => SurveyStats::meals($lines, $total),
                'rows' => match ($this->tab) {
                    'households' => $this->householdRows(),
                    'members' => $this->memberRows(),
                    'lines' => $this->lineRows(),
                    default => $this->areaRows(),
                },
                'provinces' => $areas->pluck('province')->unique()->values(),
                'municipalities' => $areas->where('province', $this->province)->pluck('municipality')->unique()->filter()->values(),
                'barangays' => $areas->where('province', $this->province)->where('municipality', $this->municipality)->pluck('barangay')->unique()->filter()->values(),
                'filter' => array_filter(['province' => $this->province, 'municipality' => $this->municipality, 'barangay' => $this->barangay]),
            ];
        } catch (\Throwable $e) {
            Log::warning('F72 page: survey database unavailable: ' . $e->getMessage());
            $data = ['offline' => true];
        }

        return view('livewire.f72-data', $data + [
            'mealNames' => SurveyStats::MEALS,
            'statusNames' => Survey::HOUSEHOLD_STATUS,
            'weightNames' => Survey::WEIGHT_METHODS,
        ]);
    }
}
