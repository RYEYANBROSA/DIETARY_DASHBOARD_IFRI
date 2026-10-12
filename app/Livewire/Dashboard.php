<?php

namespace App\Livewire;

use App\Support\Survey;
use App\Support\SurveyStats;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Landing dashboard: the survey-wide figures the old DDCS dashboard showed -
 * coverage and interview status, food recall by meal and weighing method,
 * households per municipality, and how much each field researcher recorded.
 */
#[Title('Dashboard')]
class Dashboard extends Component
{
    use WithPagination;

    public string $researcherSearch = '';

    public function updatedResearcherSearch(): void
    {
        $this->resetPage('researchers');
    }

    /** Food lines per researcher, split by recall day; names from the survey users table when it has them. */
    private function researchers()
    {
        $db = Survey::db();
        $named = Survey::has('users') && count(array_intersect(['username', 'fn', 'ln'], Survey::columns('users'))) === 3;

        return $db->table('f72 as a')
            ->when($named, fn ($q) => $q->leftJoin('users as b', 'b.username', '=', 'a.researcher'))
            ->select(
                'a.researcher',
                DB::raw($named ? 'MAX(b.fn) as fn' : 'NULL as fn'),
                DB::raw($named ? 'MAX(b.ln) as ln' : 'NULL as ln'),
                DB::raw("SUM(CASE WHEN a.foodrecall_day = '1' THEN 1 ELSE 0 END) as day1"),
                DB::raw("SUM(CASE WHEN a.foodrecall_day = '2' THEN 1 ELSE 0 END) as day2"),
                DB::raw('COUNT(*) as total'),
            )
            ->whereNotNull('a.researcher')->where('a.researcher', '!=', '')
            ->when($this->researcherSearch !== '', function ($q) use ($named) {
                $term = '%' . $this->researcherSearch . '%';
                $q->where(fn ($w) => $w->where('a.researcher', 'like', $term)
                    ->when($named, fn ($n) => $n->orWhere('b.fn', 'like', $term)->orWhere('b.ln', 'like', $term)));
            })
            ->groupBy('a.researcher')
            ->orderByDesc('total')
            ->paginate(10, pageName: 'researchers');
    }

    public function render()
    {
        $stats = SurveyStats::summary();
        $researchers = null;

        if ($stats) {
            try {
                $researchers = $this->researchers();
            } catch (\Throwable $e) {
                Log::warning('Dashboard researchers: ' . $e->getMessage());
            }
        }

        return view('livewire.dashboard', [
            'stats' => $stats,
            'researchers' => $researchers,
            'canOpenF72' => auth()->user()->canAccess(\App\Support\Modules::F72),
        ]);
    }
}
