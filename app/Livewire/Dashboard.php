<?php

namespace App\Livewire;

use App\Support\Overview;
use App\Support\RecallMonitor;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Landing dashboard. Overview (map first, see Overview): the SWS households on
 * a map coloured by 24-hr recall status, the key figures, latest activity,
 * researcher completion and what people ate. The second tab monitors the
 * 24-hour food recall in detail (RecallMonitor). Both share the province filter.
 */
#[Title('Dashboard')]
class Dashboard extends Component
{
    use WithPagination;

    /** Households per page in the recall tab's "By household" table. */
    public const HOUSEHOLDS_PER_PAGE = 25;

    /** overview | recall */
    #[Url(except: 'overview')]
    public string $tab = 'overview';

    // ----- 24-hr recall monitoring filters -----
    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    #[Url(except: '')]
    public string $province = '';

    #[Url(except: '')]
    public string $municipality = '';

    #[Url(except: '')]
    public string $barangay = '';

    #[Url(except: '')]
    public string $researcher = '';

    /** '' (both) | '1' | '2' */
    #[Url(except: '')]
    public string $day = '';

    // ----- "By household" table -----
    #[Url(as: 'hh', except: '')]
    public string $hhSearch = '';

    /** '' | a RecallMonitor::HOUSEHOLD_STATUSES key */
    #[Url(as: 'hhstatus', except: '')]
    public string $hhStatus = '';

    public function showTab(string $tab): void
    {
        $this->tab = $tab === 'recall' ? 'recall' : 'overview';
    }

    public function updated(string $property): void
    {
        // Narrowing an area level clears the levels under it
        if ($property === 'province') {
            $this->municipality = '';
            $this->barangay = '';
        }
        if ($property === 'municipality') {
            $this->barangay = '';
        }
        if (!in_array($this->day, ['', '1', '2'], true)) {
            $this->day = '';
        }
        if (!isset(RecallMonitor::HOUSEHOLD_STATUSES[$this->hhStatus])) {
            $this->hhStatus = '';
        }
        // Any filter change starts the household list again from page 1
        $this->resetPage('hhpage');
        foreach (['from', 'to'] as $date) {
            if ($this->{$date} !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->{$date})) {
                $this->{$date} = '';
            }
        }
    }

    /** Drill into an area row of the monitoring table. */
    public function drillArea(string $name): void
    {
        if ($this->province === '') {
            $this->province = $name;
        } elseif ($this->municipality === '') {
            $this->municipality = $name;
        } else {
            $this->barangay = $name;
        }
    }

    public function filterResearcher(string $name): void
    {
        $this->researcher = $this->researcher === $name ? '' : $name;
        $this->resetPage('hhpage');
    }

    public function filterHouseholds(string $status): void
    {
        $this->hhStatus = $this->hhStatus === $status || !isset(RecallMonitor::HOUSEHOLD_STATUSES[$status]) ? '' : $status;
        $this->resetPage('hhpage');
    }

    /** Read the 24-hr recalls from the live server again instead of the cached copy. */
    public function refreshLive(): void
    {
        RecallMonitor::refresh();
        $state = RecallMonitor::state();
        $this->dispatch('toast', small: true, type: $state === 'ok' ? 'success' : 'error',
            message: $state === 'ok' ? 'Live recall data refreshed.' : "The live server still can't be reached.");
    }

    public function clearRecallFilters(): void
    {
        $this->reset('from', 'to', 'province', 'municipality', 'barangay', 'researcher', 'day', 'hhSearch', 'hhStatus');
        $this->resetPage('hhpage');
    }

    private function recallFilters(): array
    {
        return [
            'from' => $this->from, 'to' => $this->to, 'province' => $this->province, 'municipality' => $this->municipality,
            'barangay' => $this->barangay, 'researcher' => $this->researcher, 'day' => $this->day,
        ];
    }



    public function render()
    {
        if ($this->tab === 'recall') {
            return $this->renderRecall();
        }

        $overview = null;
        $provinces = collect();
        try {
            $provinces = Overview::provinces();
            $overview = Overview::build($this->province);
        } catch (\Throwable $e) {
            Log::warning('Dashboard overview: ' . $e->getMessage());
        }

        return view('livewire.dashboard', [
            'overview' => $overview,
            'provinces' => $provinces,
            'canOpenF72' => auth()->user()->canAccess(\App\Support\Modules::F72),
            'canOpenSws' => auth()->user()->canAccess(\App\Support\Modules::SWS),
        ]);
    }

    private function renderRecall()
    {
        $recall = null;
        $options = null;
        $households = null;
        $householdCounts = null;
        $state = 'unreachable';

        try {
            $state = RecallMonitor::state();
            if ($state === 'ok') {
                $filters = $this->recallFilters();
                $options = RecallMonitor::options($filters);
                $recall = RecallMonitor::report($filters);

                // Counts per completion (before the status filter), then the page of rows
                $all = RecallMonitor::households($filters, $this->hhSearch);
                $householdCounts = $all->countBy('completion')->all();
                $list = $this->hhStatus !== '' ? $all->where('completion', $this->hhStatus)->values() : $all;
                $page = $this->getPage('hhpage');
                $households = new \Illuminate\Pagination\LengthAwarePaginator(
                    $list->forPage($page, self::HOUSEHOLDS_PER_PAGE)->values(), $list->count(), self::HOUSEHOLDS_PER_PAGE, $page,
                    ['pageName' => 'hhpage'],
                );
            }
        } catch (\Throwable $e) {
            $state = 'unreachable';
            Log::warning('Dashboard recall monitoring: ' . $e->getMessage());
        }

        return view('livewire.dashboard', [
            'recall' => $recall,
            'options' => $options,
            'households' => $households,
            'householdCounts' => $householdCounts,
            'recallMissing' => $state === 'missing',
            'recallState' => $state,
            'recallFetchedAt' => $state === 'ok' ? RecallMonitor::fetchedAt() : null,
            'canOpenF72' => auth()->user()->canAccess(\App\Support\Modules::F72),
        ]);
    }
}
