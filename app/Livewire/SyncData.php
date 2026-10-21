<?php

namespace App\Livewire;

use App\Models\SurveySyncRun;
use App\Support\Modules;
use App\Support\SurveySync;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Sync Data (administrators only): every survey table with its row count here
 * and on the live server, when it was last fetched, and buttons to fetch one
 * table or all of them now. A fetch only adds rows that aren't here yet. The scheduler fetches them all every
 * SurveySync::AUTO_MINUTES on its own. Only the live server is read.
 */
#[Title('Sync Data')]
class SyncData extends Component
{
    public function mount(): void
    {
        abort_unless(auth()->user()->canAccess(Modules::SYNC), 403);
    }

    public function fetch(string $table): void
    {
        abort_unless(auth()->user()->canAccess(Modules::SYNC), 403);
        if (!isset(SurveySync::TABLES[$table])) {
            return;
        }

        $run = SurveySync::fetch($table, auth()->user());
        $label = SurveySync::TABLES[$table];

        if ($run->status === 'ok') {
            $this->dispatch('toast', type: 'success', title: "{$label} fetched",
                message: number_format($run->fetched) . ' new row(s) added. ' . ($run->retained ? number_format($run->retained) . ' row(s) deleted on the live server are kept here. ' : '')
                    . number_format((int) $run->local_count) . ' here, ' . number_format((int) $run->live_count) . ' on the live server.');
        } else {
            $this->dispatch('toast', type: 'error', title: "{$label} wasn't fetched", message: $run->message ?: 'The fetch failed.');
        }
    }

    public function fetchAll(): void
    {
        abort_unless(auth()->user()->canAccess(Modules::SYNC), 403);

        $runs = SurveySync::fetchAll(auth()->user());
        $failed = collect($runs)->where('status', 'failed');

        if ($failed->isEmpty()) {
            $this->dispatch('toast', type: 'success', title: 'All tables fetched',
                message: number_format(collect($runs)->sum('fetched')) . ' new row(s) added.' . (collect($runs)->sum('retained') ? ' ' . number_format(collect($runs)->sum('retained')) . ' row(s) deleted on the live server are kept here.' : ''));
        } else {
            $this->dispatch('toast', type: 'error', title: $failed->count() . ' table(s) not fetched',
                message: $failed->map(fn ($r) => SurveySync::TABLES[$r->table] . ': ' . $r->message)->implode(' · '));
        }
    }

    public function render()
    {
        try {
            $overview = SurveySync::overview();
        } catch (\Throwable $e) {
            Log::warning('Sync Data: ' . $e->getMessage());
            $overview = ['reachable' => false, 'tables' => []];
        }

        $lastAuto = SurveySync::lastAuto();

        return view('livewire.sync-data', [
            'overview' => $overview,
            'lastAuto' => $lastAuto,
            // The scheduler should have fetched within the last interval (plus a little slack)
            'autoLate' => !$lastAuto || $lastAuto->created_at->lt(now()->subMinutes(SurveySync::AUTO_MINUTES + 5)),
            'runs' => SurveySyncRun::with('user')->latest('id')->limit(15)->get(),
        ]);
    }
}
