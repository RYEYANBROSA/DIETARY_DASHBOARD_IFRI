<?php

namespace App\Livewire;

use App\Models\F11Activity;
use App\Models\F11Change;
use App\Models\User;
use App\Support\F11Record;
use App\Support\F11Store;
use App\Support\Survey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * F11 encoding and upload, for validators. Members are written straight to the
 * survey database's f11 table - one at a time from the form, or in bulk from a
 * CSV made with the downloadable template. Members are never deleted - a
 * wrong record is corrected by editing it. Every write is recorded: who
 * encoded, edited or uploaded each member is shown beside it, and its full
 * history (with old and new values) can be opened for validation.
 */
#[Title('F11 Encoding & Upload')]
class F11Encoding extends Component
{
    use WithFileUploads, WithPagination;

    /** records | encode | upload */
    #[Url(except: 'records')]
    public string $tab = 'records';

    // Records list filters
    #[Url(except: '')]
    public string $province = '';

    #[Url(except: '')]
    public string $municipality = '';

    #[Url(except: '')]
    public string $barangay = '';

    #[Url(except: '')]
    public string $search = '';

    /** Only members this account added or edited (user id). */
    #[Url(as: 'by', except: '')]
    public string $changedBy = '';

    /** Only members added or changed by this upload (f11_activities id). */
    #[Url(as: 'upload', except: '')]
    public string $uploadBatch = '';

    /** The member whose history is open, if any. */
    public ?int $historyId = null;

    // Encode form
    public array $member = [];

    public ?int $editingId = null;

    /** The listed household picked for the form, as "id1|id2|id3". */
    public string $household = '';

    // Upload
    public $file;

    /** The checked upload waiting to be imported - see checkFile(). */
    public ?array $upload = null;

    public function mount(): void
    {
        $this->member = $this->blankMember();
    }

    public function showTab(string $tab): void
    {
        $this->tab = in_array($tab, ['records', 'encode', 'upload'], true) ? $tab : 'records';
    }

    // ----- Records list -----

    public function updated(string $property): void
    {
        if ($property === 'province') {
            $this->municipality = '';
            $this->barangay = '';
        }
        if ($property === 'municipality') {
            $this->barangay = '';
        }
        if (in_array($property, ['province', 'municipality', 'barangay', 'search', 'changedBy', 'uploadBatch'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('province', 'municipality', 'barangay', 'search', 'changedBy', 'uploadBatch');
        $this->resetPage();
    }

    /** List the members an upload added or changed. */
    public function showUpload(int $activityId): void
    {
        $this->reset('province', 'municipality', 'barangay', 'search', 'changedBy');
        $this->uploadBatch = (string) $activityId;
        $this->tab = 'records';
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

    // ----- Encode form -----

    public function edit(int $id): void
    {
        $row = Survey::db()->table('f11')->where('id', $id)->first();
        if (!$row) {
            $this->dispatch('toast', message: 'That member no longer exists.', type: 'error');

            return;
        }

        $this->resetValidation();
        $this->member = F11Record::toForm($row);
        $this->editingId = $id;
        $this->household = implode('|', [$this->member['identifier1'], $this->member['identifier2'], $this->member['identifier3']]);
        $this->tab = 'encode';
    }

    public function clearMember(): void
    {
        $this->member = $this->blankMember();
        $this->editingId = null;
        $this->household = '';
        $this->resetValidation();
    }

    public function updatedMember(mixed $value, ?string $key = null): void
    {
        if ($key === 'province') {
            $this->member['municipality'] = '';
            $this->member['barangay'] = '';
        }
        if (in_array($key, ['province', 'municipality'], true)) {
            $this->member['barangay'] = '';
        }
        if (in_array($key, ['province', 'municipality', 'barangay'], true)) {
            $this->household = '';
        }
    }

    public function updatedHousehold(string $value): void
    {
        if ($value === '') {
            return;
        }

        [$this->member['identifier1'], $this->member['identifier2'], $this->member['identifier3']] = array_pad(explode('|', $value, 3), 3, '');
        $this->resetValidation(['member.identifier1', 'member.identifier2', 'member.identifier3']);
    }

    public function save(): void
    {
        $this->resetValidation();
        [$record, $errors] = F11Record::check($this->member, F11Store::knownAreas());

        if (!$record) {
            foreach ($errors as $column => $message) {
                $this->addError('member.' . $column, $message);
            }

            return;
        }

        if ($other = F11Store::findByKey(F11Record::key($record), $this->editingId)) {
            $this->addError('member.member_no', "Member {$record['member_no']} of this household is already encoded ({$other->surname}, {$other->givenname}).");

            return;
        }

        $wasEditing = $this->editingId !== null;
        F11Store::save($record, $this->editingId, auth()->user());

        $name = "{$record['surname']}, {$record['givenname']}";
        $this->dispatch('toast', message: $wasEditing ? "{$name} was updated." : "{$name} was saved to F11.", type: 'success');

        if ($wasEditing) {
            $this->clearMember();
            $this->tab = 'records';
        } else {
            // Keep the area and household for the next member of the same household
            $keep = array_intersect_key($this->member, array_flip([...Survey::HOUSEHOLD_KEYS, 'foodrecall_day', 'reference_date']));
            $this->member = [...$this->blankMember(), ...$keep];
        }
    }

    // ----- Upload -----

    /** A chosen file is checked as soon as it has uploaded - no extra click. */
    public function updatedFile(): void
    {
        $this->checkFile();
    }

    public function checkFile(): void
    {
        $this->upload = null;
        $maxKb = F11Store::maxUploadKb();

        $this->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', "max:{$maxKb}"],
        ], [
            'file.required' => 'Choose a CSV file first.',
            'file.mimes' => 'This is not a CSV file. Save the F11 template as CSV (.csv) and choose it again.',
            'file.max' => 'The file is larger than ' . round($maxKb / 1024, 1) . ' MB. Split it into smaller files.',
        ], ['file' => 'F11 file']);

        $name = $this->file->getClientOriginalName();
        [$rows, $error] = F11Store::readCsv($this->file->getRealPath());
        $this->reset('file');

        if ($error) {
            $this->upload = null;
            $this->addError('file', $error);

            return;
        }

        $result = F11Store::checkUpload($rows);

        // The checked records wait in the cache rather than in the component,
        // so thousands of rows don't travel back and forth with every request
        $token = (string) Str::uuid();
        Cache::put("f11.upload.{$token}", $result['records'], now()->addMinutes(30));

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
    }

    public function import(): void
    {
        $records = $this->upload ? Cache::pull("f11.upload.{$this->upload['token']}") : null;

        if (!$records) {
            $this->upload = null;
            $this->dispatch('toast', message: 'The checked file has expired. Choose it again.', type: 'error');

            return;
        }

        [$inserted, $updated] = F11Store::import($records, $this->upload['name'], auth()->user());
        $this->upload = null;

        $this->dispatch('toast', message: "Imported {$inserted} new and updated {$updated} existing member(s).", type: 'success');
    }

    public function discardUpload(): void
    {
        if ($this->upload) {
            Cache::forget("f11.upload.{$this->upload['token']}");
        }
        $this->upload = null;
        $this->resetValidation('file');
    }

    public function render()
    {
        try {
            $areas = collect(Cache::remember('survey.f11.areas', now()->addMinutes(10), fn () => F11Store::areaOptions()->all()));
            $data = ['offline' => false] + $this->areaLists($areas) + $this->recordList() + [
                'households' => $this->member['barangay'] !== ''
                    ? F11Store::households($this->member['province'], $this->member['municipality'], $this->member['barangay'])
                    : collect(),
            ];
        } catch (\Throwable $e) {
            Log::warning('F11 page: survey database unavailable: ' . $e->getMessage());
            $data = ['offline' => true];
        }

        return view('livewire.f11-encoding', $data + [
            'activities' => F11Activity::with('user')->latest('id')->limit(8)->get(),
            'editors' => User::whereIn('id', F11Change::select('user_id')->distinct())->orderBy('first_name')->get(),
            'uploads' => F11Activity::with('user')->where('action', 'imported')->latest('id')->limit(50)->get(),
            'history' => $this->historyId
                ? F11Change::with('user', 'upload')->where('f11_id', $this->historyId)->orderByDesc('id')->get()
                : null,
            'labels' => F11Record::LABELS,
            'categories' => F11Record::CATEGORIES,
            'pscOptions' => F11Record::PSC,
            'statuses' => F11Record::STATUS,
            'columns' => F11Record::COLUMNS,
        ]);
    }

    private function areaLists($areas): array
    {
        $children = fn ($province, $municipality = null) => $areas
            ->where('province', $province)
            ->when($municipality !== null, fn ($c) => $c->where('municipality', $municipality));

        return [
            'provinces' => $areas->pluck('province')->unique()->values(),
            // Form cascade
            'municipalities' => $children($this->member['province'])->pluck('municipality')->unique()->filter()->values(),
            'barangays' => $children($this->member['province'], $this->member['municipality'])->pluck('barangay')->unique()->filter()->values(),
            // Records filter cascade
            'filterMunicipalities' => $children($this->province)->pluck('municipality')->unique()->filter()->values(),
            'filterBarangays' => $children($this->province, $this->municipality)->pluck('barangay')->unique()->filter()->values(),
        ];
    }

    private function recordList(): array
    {
        $query = Survey::db()->table('f11')
            ->when($this->province !== '', fn ($q) => $q->where('province', $this->province))
            ->when($this->municipality !== '', fn ($q) => $q->where('municipality', $this->municipality))
            ->when($this->barangay !== '', fn ($q) => $q->where('barangay', $this->barangay))
            ->when($this->search !== '', function ($q) {
                $term = '%' . $this->search . '%';
                $q->where(fn ($w) => $w
                    ->where('surname', 'like', $term)
                    ->orWhere('givenname', 'like', $term)
                    ->orWhere('identifier1', 'like', $term)
                    ->orWhere('identifier2', 'like', $term)
                    ->orWhere('sws_member_no', 'like', $term));
            })
            // The history is in the app's database, which may not be the survey
            // database - so it narrows the list by id rather than by a join
            ->when($this->changedBy !== '' || $this->uploadBatch !== '', fn ($q) => $q->whereIn('id', F11Change::query()
                ->when($this->changedBy !== '', fn ($c) => $c->where('user_id', (int) $this->changedBy))
                ->when($this->uploadBatch !== '', fn ($c) => $c->where('f11_activity_id', (int) $this->uploadBatch))
                ->distinct()->pluck('f11_id')->all()));

        $records = $query->orderByDesc('id')->paginate(15);

        return [
            'records' => $records,
            'trail' => $this->trail(collect($records->items())->pluck('id')->all()),
            'totalMembers' => Survey::db()->table('f11')->count(),
        ];
    }

    /**
     * For each listed member: who added it (and how), and the latest change.
     * Members with no history came from the field tablets before tracking began.
     */
    private function trail(array $ids): array
    {
        $trail = [];
        foreach (F11Change::with('user', 'upload')->whereIn('f11_id', $ids)->orderBy('id')->get()->groupBy('f11_id') as $id => $changes) {
            $added = $changes->firstWhere('action', 'created');
            $latest = $changes->last();
            $trail[$id] = [
                'added' => $added,
                'latest' => $latest !== $added ? $latest : null,
                'count' => $changes->count(),
            ];
        }

        return $trail;
    }

    private function blankMember(): array
    {
        return [...array_fill_keys(F11Record::COLUMNS, ''), 'foodrecall_day' => '1', 'interview_status' => '1'];
    }
}
