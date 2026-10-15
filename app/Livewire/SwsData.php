<?php

namespace App\Livewire;

use App\Models\SwsActivity;
use App\Models\SwsChange;
use App\Models\User;
use App\Support\F11Store;
use App\Support\Survey;
use App\Support\SwsRecord;
use App\Support\SwsStore;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * SWS Upload Data, for validators: the SWS transmission records in sws_data.
 * Works like F11 - add or edit one record on the form, or upload many from
 * the SWS template (CSV or XLSX) - plus a full-width view of every column for
 * reading through large amounts of data, and a map of the households from
 * their latitude and longitude. Records are transmitted from here to the live
 * server the field researchers' tablets read (see SwsStore::transmit()). Records are never deleted, and every
 * write is tracked: who added or edited each record, by form or upload.
 */
#[Title('SWS Upload Data')]
class SwsData extends Component
{
    use WithFileUploads, WithPagination;

    public const PER_PAGE = [25, 50, 100, 250];

    /**
     * The "Added / edited by" and "Upload" filters, hidden for now. They still
     * work (e.g. "View records" under an upload sets the upload filter); set
     * this to true to show them again.
     */
    public const SHOW_AUDIT_FILTERS = false;

    /** Most households drawn on the map at once. */
    public const MAP_LIMIT = 5000;

    /** data | map | entry | upload */
    #[Url(except: 'data')]
    public string $tab = 'data';

    // ----- Data view -----
    #[Url(except: '')]
    public string $city = '';

    #[Url(except: '')]
    public string $district = '';

    #[Url(except: '')]
    public string $barangay = '';

    #[Url(except: '')]
    public string $search = '';

    #[Url(as: 'by', except: '')]
    public string $changedBy = '';

    #[Url(as: 'upload', except: '')]
    public string $uploadBatch = '';

    /** '' or 'missing' - records without usable coordinates. */
    #[Url(except: '')]
    public string $coords = '';

    /** '' | 'yes' | 'no' - transmitted to the live server or not. */
    #[Url(as: 'sent', except: '')]
    public string $transmitted = '';

    #[Url(except: 50)]
    public int $perPage = 50;

    #[Url(except: 'id')]
    public string $sort = 'id';

    #[Url(except: 'desc')]
    public string $dir = 'desc';

    public ?int $historyId = null;

    /** The record the map opens on (see showOnMap). */
    public ?int $focusId = null;

    // ----- Form -----
    public array $record = [];

    public ?int $editingId = null;

    // ----- Upload -----
    public $file;

    public ?array $upload = null;

    public function mount(): void
    {
        $this->record = array_fill_keys(SwsRecord::COLUMNS, '');
        $this->perPage = in_array($this->perPage, self::PER_PAGE, true) ? $this->perPage : 50;
    }

    public function showTab(string $tab): void
    {
        $this->tab = in_array($tab, ['data', 'map', 'entry', 'upload'], true) ? $tab : 'data';
        $this->focusId = null;
    }

    public function updated(string $property): void
    {
        if ($property === 'city') {
            $this->district = '';
            $this->barangay = '';
        }
        if ($property === 'district') {
            $this->barangay = '';
        }
        if ($property === 'perPage' && !in_array($this->perPage, self::PER_PAGE, true)) {
            $this->perPage = 50;
        }
        if (in_array($property, ['city', 'district', 'barangay', 'search', 'changedBy', 'uploadBatch', 'coords', 'transmitted', 'perPage'], true)) {
            $this->resetPage();
        }
    }

    public function sortBy(string $column): void
    {
        if (!in_array($column, SwsStore::SORTABLE, true)) {
            return;
        }

        $this->dir = $this->sort === $column && $this->dir === 'asc' ? 'desc' : 'asc';
        $this->sort = $column;
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('city', 'district', 'barangay', 'search', 'changedBy', 'uploadBatch', 'coords', 'transmitted');
        $this->resetPage();
    }

    /** Open the map zoomed in on one household (filters cleared so it is on it). */
    public function showOnMap(int $id): void
    {
        $this->reset('city', 'district', 'barangay', 'search', 'changedBy', 'uploadBatch', 'coords', 'transmitted');
        $this->tab = 'map';
        $this->focusId = $id;
    }

    /** From the map: list the matching records that can't be placed on it. */
    public function showMissingCoordinates(): void
    {
        $this->coords = 'missing';
        $this->tab = 'data';
        $this->resetPage();
    }

    public function showUpload(int $activityId): void
    {
        $this->reset('city', 'district', 'barangay', 'search', 'changedBy');
        $this->uploadBatch = (string) $activityId;
        $this->tab = 'data';
        $this->resetPage();
    }

    public function showHistory(int $id): void
    {
        $this->historyId = $id;
    }

    public function closeHistory(): void
    {
        $this->historyId = null;
    }

    // ----- Form -----

    public function edit(int $id): void
    {
        $row = SwsStore::table()->where('id', $id)->first();
        if (!$row) {
            $this->dispatch('toast', message: 'That record no longer exists.', type: 'error');

            return;
        }

        if (SwsStore::isLocked($row)) {
            $this->lockedAlert($row);

            return;
        }

        $this->resetValidation();
        $this->record = SwsRecord::toForm($row);
        $this->editingId = $id;
        $this->historyId = null;
        $this->tab = 'entry';
    }

    private function lockedAlert(object $row): void
    {
        $when = $row->transmitted_at ? ' on ' . \Illuminate\Support\Carbon::parse($row->transmitted_at)->format('M j, Y g:i A') : '';
        $this->dispatch('toast', type: 'warning', title: 'Cannot edit - already transmitted',
            message: "{$row->sws_member_no} was transmitted to the live server{$when}. " . 'This record is already on the live server, so it cannot be edited here anymore.');
    }

    public function clearRecord(): void
    {
        $this->record = array_fill_keys(SwsRecord::COLUMNS, '');
        $this->editingId = null;
        $this->resetValidation();
    }

    public function save(): void
    {
        $this->resetValidation();
        [$record, $errors] = SwsRecord::check($this->record);

        if (!$record) {
            foreach ($errors as $column => $message) {
                $this->addError('record.' . $column, $message);
            }

            return;
        }

        // Transmitted while the form was open
        if ($this->editingId && SwsStore::isLocked($row = SwsStore::table()->where('id', $this->editingId)->first())) {
            $this->lockedAlert($row);
            $this->clearRecord();
            $this->tab = 'data';

            return;
        }

        if ($other = SwsStore::findByKey($record, $this->editingId)) {
            $this->addError('record.sws_member_no', SwsRecord::keyLabel($record) . " is already used by {$other->lastname}, {$other->firstname} - the same identifiers and SWS member no. can only be used once.");

            return;
        }

        $wasEditing = $this->editingId !== null;
        SwsStore::save($record, $this->editingId, auth()->user());

        $name = SwsRecord::name($record);
        $this->dispatch('toast', message: $wasEditing ? "{$name} was updated." : "{$name} was added to SWS data.", type: 'success');

        if ($wasEditing) {
            $this->clearRecord();
            $this->tab = 'data';
        } else {
            // Keep the area for the next respondent from the same place
            $keep = array_intersect_key($this->record, array_flip(['city', 'district', 'barangay']));
            $this->record = [...array_fill_keys(SwsRecord::COLUMNS, ''), ...$keep];
        }
    }

    // ----- Upload -----

    /** A chosen file is checked as soon as it has uploaded. */
    public function updatedFile(): void
    {
        $this->checkFile();
    }

    public function checkFile(): void
    {
        $this->upload = null;
        $this->resetValidation('file');
        $maxKb = F11Store::maxUploadKb();

        $check = Validator::make(['file' => $this->file], [
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx', "max:{$maxKb}"],
        ], [
            'file.required' => 'Choose a CSV or XLSX file first.',
            'file.mimes' => 'Use the SWS template saved as CSV (.csv) or Excel (.xlsx).',
            'file.max' => 'The file is larger than ' . round($maxKb / 1024, 1) . ' MB. Split it into smaller files.',
        ], ['file' => 'SWS file']);

        if ($check->fails()) {
            $this->reset('file');
            $this->fileProblem($check->errors()->first('file'));

            return;
        }

        $name = $this->file->getClientOriginalName();
        $extension = strtolower($this->file->getClientOriginalExtension());
        [$rows, $error] = SwsStore::readFile($this->file->getRealPath(), $extension);
        $this->reset('file');

        if ($error) {
            $this->fileProblem($error);

            return;
        }

        $result = SwsStore::checkUpload($rows);
        $token = (string) Str::uuid();
        Cache::put("sws.upload.{$token}", $result['records'], now()->addMinutes(30));

        $this->upload = [
            'token' => $token,
            'name' => $name,
            'rows' => count($rows),
            'valid' => count($result['records']),
            'inserts' => $result['inserts'],
            'updates' => $result['updates'],
            'problemCount' => count($result['problems']),
            'problems' => array_slice($result['problems'], 0, 200),
        ];

        $this->announceCheck('record');
    }

    /** The file can't be used at all: say why inline and in an alert. */
    private function fileProblem(string $message): void
    {
        $this->addError('file', $message);
        $this->dispatch('toast', type: 'error', title: "This file can't be used", message: $message);
    }

    /** The result of checking an upload, as an alert over the result panel. */
    private function announceCheck(string $noun): void
    {
        $u = $this->upload;
        $summary = "{$u['valid']} {$noun}(s) ready: {$u['inserts']} new, {$u['updates']} update(s).";

        if ($u['valid'] === 0) {
            $this->dispatch('toast', type: 'error', title: 'No rows can be imported', message: "All {$u['rows']} row(s) have problems. They are listed on the page - fix them in the file and upload it again.");
        } elseif ($u['problemCount']) {
            $this->dispatch('toast', type: 'warning', title: 'File checked - some rows have problems', message: "{$summary} {$u['problemCount']} problem(s) are listed on the page; those rows will be left out.");
        } else {
            $this->dispatch('toast', type: 'success', persist: true, title: 'File checked', message: "{$summary} Review it, then press Import.");
        }
    }

    /**
     * Transmit the records under the current filters that the live server
     * doesn't have yet (or has an older version of). The confirmation and the
     * busy alert are shown in the browser before this runs.
     */
    public function transmit(): void
    {
        $ids = SwsStore::pending(self::filtered($this->filterValues()))->orderBy('id')->pluck('id')->all();

        if ($ids === []) {
            $this->dispatch('toast', type: 'info', title: 'Nothing to transmit', message: 'Every record here is already on the live server.');

            return;
        }

        try {
            if (!SwsStore::liveKeyReady()) {
                $this->dispatch('toast', type: 'error', title: 'The live server needs an update first',
                    message: 'Its sws_data is still unique on SWS member no. alone, so households sharing a member no. would overwrite each other there. An administrator has to run "php artisan sws:live-key --apply" once; then transmit again. Nothing was sent.');

                return;
            }

            [$sent, $new, $updated, $version] = SwsStore::transmit($ids, auth()->user());
        } catch (\Throwable $e) {
            Log::error('SWS transmission failed: ' . $e->getMessage());
            $this->dispatch('toast', type: 'error', title: 'Transmission failed',
                message: 'The live server could not be reached or refused the data, so nothing more was marked as transmitted. Check the connection and try again. (' . Str::limit($e->getMessage(), 160) . ')');

            return;
        }

        $this->dispatch('toast', type: 'success', persist: true, title: 'Data transmitted',
            message: "{$sent} SWS record(s) are now on the live server ({$new} new, {$updated} updated). "
                . ($version ? "Listing version is now {$version}." : 'The listing version could not be updated (no listing_version table on the live server), but the data was sent.'));
    }

    public function import(): void
    {
        $records = $this->upload ? Cache::pull("sws.upload.{$this->upload['token']}") : null;

        if (!$records) {
            $this->upload = null;
            $this->dispatch('toast', message: 'The checked file has expired. Choose it again.', type: 'error');

            return;
        }

        [$inserted, $updated] = SwsStore::import($records, $this->upload['name'], auth()->user());
        $this->upload = null;

        $this->dispatch('toast', type: 'success', persist: true, title: 'Import complete', message: "Added {$inserted} new and updated {$updated} existing record(s).");
    }

    public function discardUpload(): void
    {
        if ($this->upload) {
            Cache::forget("sws.upload.{$this->upload['token']}");
        }
        $this->upload = null;
        $this->resetValidation('file');
    }

    // ----- Data -----

    /** sws_data narrowed by the data view's filters. Shared with the CSV export. */
    public static function filtered(array $f): Builder
    {
        return SwsStore::table()
            ->when(($f['city'] ?? '') !== '', fn ($q) => $q->where('city', $f['city']))
            ->when(($f['district'] ?? '') !== '', fn ($q) => $q->where('district', $f['district']))
            ->when(($f['barangay'] ?? '') !== '', fn ($q) => $q->where('barangay', $f['barangay']))
            ->when(($f['search'] ?? '') !== '', function ($q) use ($f) {
                $term = '%' . $f['search'] . '%';
                $q->where(fn ($w) => $w
                    ->where('sws_member_no', 'like', $term)
                    ->orWhere('lastname', 'like', $term)
                    ->orWhere('firstname', 'like', $term)
                    ->orWhere('middlename', 'like', $term)
                    ->orWhere('identifier1', 'like', $term)
                    ->orWhere('street_name', 'like', $term)
                    ->orWhere('contact_number', 'like', $term)
                    ->orWhere('email_address', 'like', $term)
                    ->orWhere('indexwomenname', 'like', $term));
            })
            ->when(($f['sent'] ?? '') === 'yes', fn ($q) => $q->where('transmitted', 'yes'))
            ->when(($f['sent'] ?? '') === 'no', fn ($q) => SwsStore::pending($q))
            ->when(($f['coords'] ?? '') === 'missing', fn ($q) => $q->where(fn ($w) => $w
                ->whereNull('latitude')->orWhere('latitude', '')
                ->orWhereNull('longitude')->orWhere('longitude', '')))
            // History lives in the app's database - narrow by id, not a join
            ->when(($f['by'] ?? '') !== '' || ($f['upload'] ?? '') !== '', fn ($q) => $q->whereIn('id', SwsChange::query()
                ->when(($f['by'] ?? '') !== '', fn ($c) => $c->where('user_id', (int) $f['by']))
                ->when(($f['upload'] ?? '') !== '', fn ($c) => $c->where('sws_activity_id', (int) $f['upload']))
                ->distinct()->pluck('sws_id')->all()));
    }

    private function filterValues(): array
    {
        return array_filter([
            'city' => $this->city, 'district' => $this->district, 'barangay' => $this->barangay,
            'search' => $this->search, 'by' => $this->changedBy, 'upload' => $this->uploadBatch,
            'coords' => $this->coords,
            'sent' => $this->transmitted,
        ], fn ($v) => $v !== '');
    }

    public function render()
    {
        try {
            $areas = SwsStore::table()->select('city', 'district', 'barangay')->distinct()
                ->orderBy('city')->orderBy('district')->orderBy('barangay')->get();

            $sort = in_array($this->sort, SwsStore::SORTABLE, true) ? $this->sort : 'id';
            $rows = self::filtered($this->filterValues())
                ->orderBy($sort, $this->dir === 'asc' ? 'asc' : 'desc')
                ->when($sort !== 'id', fn ($q) => $q->orderBy('id'))
                ->paginate($this->perPage);

            $data = [
                'offline' => false,
                'rows' => $rows,
                'trail' => $this->trail(collect($rows->items())->pluck('id')->all()),
                'total' => SwsStore::table()->count(),
                'cities' => $areas->pluck('city')->filter()->unique()->values(),
                'districts' => $areas->where('city', $this->city)->pluck('district')->filter()->unique()->values(),
                'barangays' => $areas->where('city', $this->city)->when($this->district !== '', fn ($c) => $c->where('district', $this->district))->pluck('barangay')->filter()->unique()->values(),
                // Suggestions for the form's area fields
                'allCities' => $areas->pluck('city')->filter()->unique()->values(),
                'allDistricts' => $areas->pluck('district')->filter()->unique()->values(),
                'allBarangays' => $areas->pluck('barangay')->filter()->unique()->values(),
                // Not yet on the live server: in total, and under the current filters
                'pendingAll' => SwsStore::pending(SwsStore::table())->count(),
                'pendingHere' => SwsStore::pending(self::filtered($this->filterValues()))->count(),
                'liveDatabase' => config('database.connections.' . SwsStore::LIVE_CONNECTION . '.database'),
                'map' => $this->tab === 'map' ? $this->mapData() : null,
            ];
        } catch (\Throwable $e) {
            Log::warning('SWS page: survey database unavailable: ' . $e->getMessage());
            $data = ['offline' => true];
        }

        return view('livewire.sws-data', $data + [
            'columns' => SwsRecord::COLUMNS,
            'labels' => SwsRecord::LABELS,
            'sortable' => SwsStore::SORTABLE,
            'perPageOptions' => self::PER_PAGE,
            'activities' => SwsActivity::with('user')->latest('id')->limit(8)->get(),
            'editors' => User::whereIn('id', SwsChange::select('user_id')->distinct())->orderBy('first_name')->get(),
            'uploads' => SwsActivity::where('action', 'imported')->latest('id')->limit(50)->get(),
            'history' => $this->historyId
                ? SwsChange::with('user', 'upload')->where('sws_id', $this->historyId)->orderByDesc('id')->get()
                : null,
            'maxMb' => round(F11Store::maxUploadKb() / 1024, 1),
            'exportQuery' => $this->filterValues(),
        ]);
    }

    /**
     * Households for the map: the filtered records with usable coordinates,
     * as compact rows [id, lat, lng, member no., name, address, area, contact],
     * plus counts of those that can't be placed or fall outside the Philippines.
     */
    private function mapData(): array
    {
        $filters = $this->filterValues();
        unset($filters['coords']);
        $matching = self::filtered($filters)->count();

        $points = [];
        $outside = 0;
        self::filtered($filters)
            ->whereNotNull('latitude')->where('latitude', '!=', '')
            ->whereNotNull('longitude')->where('longitude', '!=', '')
            ->orderBy('id')
            ->limit(self::MAP_LIMIT)
            ->get(['id', 'latitude', 'longitude', 'sws_member_no', 'lastname', 'firstname', 'middlename', 'suffix',
                'floor_no', 'building_no', 'block', 'street_name', 'subd', 'barangay', 'district', 'city', 'contact_number'])
            ->each(function ($r) use (&$points, &$outside) {
                if (!is_numeric($r->latitude) || !is_numeric($r->longitude)) {
                    return;
                }
                $lat = (float) $r->latitude;
                $lng = (float) $r->longitude;
                if ($lat < 4.2 || $lat > 21.5 || $lng < 116 || $lng > 127) {
                    $outside++;
                }
                $points[] = [
                    $r->id, round($lat, 7), round($lng, 7), $r->sws_member_no,
                    trim("{$r->lastname}, {$r->firstname} {$r->middlename} {$r->suffix}"),
                    collect([$r->floor_no, $r->building_no, $r->block, $r->street_name, $r->subd])->filter()->implode(', '),
                    collect([$r->barangay, $r->district, $r->city])->filter()->implode(', '),
                    (string) $r->contact_number,
                ];
            });

        $placed = self::filtered($filters)
            ->whereNotNull('latitude')->where('latitude', '!=', '')
            ->whereNotNull('longitude')->where('longitude', '!=', '')->count();

        return [
            'points' => $points,
            'matching' => $matching,
            'missing' => $matching - $placed,
            'outside' => $outside,
            'capped' => $placed > self::MAP_LIMIT,
            'focus' => $this->focusId,
            // Re-create the map when the set of households changes
            'key' => md5(json_encode($filters) . '|' . $this->focusId . '|' . count($points)),
        ];
    }

    /** Per listed record: who added it, and the latest change. */
    private function trail(array $ids): array
    {
        $trail = [];
        foreach (SwsChange::with('user', 'upload')->whereIn('sws_id', $ids)->orderBy('id')->get()->groupBy('sws_id') as $id => $changes) {
            $added = $changes->firstWhere('action', 'created');
            $latest = $changes->last();
            $trail[$id] = ['added' => $added, 'latest' => $latest !== $added ? $latest : null];
        }

        return $trail;
    }
}
