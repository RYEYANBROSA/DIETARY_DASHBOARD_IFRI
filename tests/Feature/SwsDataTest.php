<?php

namespace Tests\Feature;

use App\Livewire\SwsData;
use App\Models\SwsActivity;
use App\Models\SwsChange;
use App\Models\User;
use App\Support\Modules;
use App\Support\SwsRecord;
use App\Support\SwsStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\Concerns\UsesSurveyDatabase;
use Tests\TestCase;

class SwsDataTest extends TestCase
{
    use RefreshDatabase, UsesSurveyDatabase;

    private User $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSurveyDatabase();
        $this->validator = User::factory()->validator()->create();
    }

    private function record(array $overrides = []): array
    {
        return [
            'city' => 'Quezon City', 'district' => 'District 1', 'barangay' => 'Barangay 1',
            'identifier1' => 'QC001', 'identifier2' => 'QC001', 'identifier3' => 'QC001',
            'sws_member_no' => 'SWS_001', 'lastname' => 'Dela Cruz', 'firstname' => 'Juan', 'suffix' => '', 'middlename' => 'Santos',
            'floor_no' => 'Floor 2', 'building_no' => 'Building 3', 'block' => 'Blk 81', 'street_name' => 'Maginhawa', 'subd' => 'Teachers Village',
            'contact_number' => '9171234567', 'email_address' => 'Juan@Example.com', 'latitude' => '14.6427441299736', 'longitude' => '121.060726102549',
            'indexwomenname' => 'Maria Dela Cruz', 'idp_age' => '34',
            ...$overrides,
        ];
    }

    private function csv(array ...$records): string
    {
        $lines = [implode(',', array_map('strtoupper', SwsRecord::COLUMNS))];
        foreach ($records as $r) {
            $lines[] = implode(',', array_map(fn ($c) => '"' . ($r[$c] ?? '') . '"', SwsRecord::COLUMNS));
        }

        return implode("\n", $lines);
    }

    /** A minimal .xlsx: shared-string header, a number cell, a gap (blank SUFFIX). */
    private function xlsx(array $record): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sws') . '.xlsx';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        $headers = array_map('strtoupper', SwsRecord::COLUMNS);
        $col = fn ($i) => ($i >= 26 ? chr(64 + intdiv($i, 26)) : '') . chr(65 + $i % 26);

        $shared = '';
        $row1 = '';
        foreach ($headers as $i => $h) {
            $shared .= "<si><t>{$h}</t></si>";
            $row1 .= "<c r=\"{$col($i)}1\" t=\"s\"><v>{$i}</v></c>";
        }
        $row2 = '';
        foreach (SwsRecord::COLUMNS as $i => $c) {
            $v = $record[$c] ?? '';
            if ($v === '') {
                continue; // Excel leaves empty cells out
            }
            $row2 .= is_numeric($v)
                ? "<c r=\"{$col($i)}2\"><v>{$v}</v></c>"
                : "<c r=\"{$col($i)}2\" t=\"inlineStr\"><is><t>" . htmlspecialchars($v) . '</t></is></c>';
        }

        $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' . $shared . '</sst>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1">' . $row1 . '</row><row r="2">' . $row2 . '</row></sheetData></worksheet>');
        $zip->close();

        return $path;
    }

    public function test_validators_and_administrators_get_sws_by_default_but_clients_do_not(): void
    {
        $this->assertTrue($this->validator->canAccess(Modules::SWS));
        $this->assertTrue(User::factory()->administrator()->create()->canAccess(Modules::SWS));
        $client = User::factory()->create();
        $this->assertFalse($client->canAccess(Modules::SWS));

        $this->actingAs($client)->get('/sws')->assertForbidden();
        $this->actingAs($client)->get('/sws/template')->assertForbidden();
        $this->actingAs($this->validator)->get('/sws')->assertOk()->assertSee('SWS Upload Data');
    }

    public function test_the_template_has_the_sws_variable_names(): void
    {
        $body = $this->actingAs($this->validator)->get('/sws/template')->assertOk()->getContent();

        $this->assertSame(['CITY', 'DISTRICT', 'BARANGAY', 'IDENTIFIER1', 'IDENTIFIER2', 'IDENTIFIER3', 'SWS_MEMBER_NO', 'LASTNAME', 'FIRSTNAME', 'SUFFIX', 'MIDDLENAME', 'FLOOR_NO', 'BUILDING_NO', 'BLOCK', 'STREET_NAME', 'SUBD', 'CONTACT_NUMBER', 'EMAIL_ADDRESS', 'LATITUDE', 'LONGITUDE', 'INDEXWOMENNAME', 'IDP_AGE'], str_getcsv(trim(substr($body, 3))));
    }

    public function test_a_record_is_added_cleaned_and_tracked(): void
    {
        $this->actingAs($this->validator);

        Livewire::test(SwsData::class)->set('record', $this->record())->call('save')->assertHasNoErrors();

        $row = SwsStore::table()->first();
        $this->assertSame(['QUEZON CITY', 'DELA CRUZ', '09171234567', 'juan@example.com', 34], [$row->city, $row->lastname, $row->contact_number, $row->email_address, (int) $row->idp_age]);
        $this->assertSame(['created', 'form', $this->validator->id], [SwsChange::sole()->action, SwsChange::sole()->source, SwsChange::sole()->user_id]);
    }

    public function test_bad_values_and_duplicate_member_numbers_are_refused(): void
    {
        $this->actingAs($this->validator);
        Livewire::test(SwsData::class)->set('record', $this->record())->call('save');

        Livewire::test(SwsData::class)
            ->set('record', $this->record(['sws_member_no' => 'SWS_001', 'lastname' => 'Other']))
            ->call('save')
            ->assertHasErrors('record.sws_member_no');

        Livewire::test(SwsData::class)
            ->set('record', $this->record(['sws_member_no' => 'SWS_002', 'latitude' => '214', 'email_address' => 'not-an-email', 'idp_age' => 'old', 'lastname' => '']))
            ->call('save')
            ->assertHasErrors(['record.latitude', 'record.email_address', 'record.idp_age', 'record.lastname']);

        $this->assertSame(1, SwsStore::table()->count());
    }

    public function test_an_edit_records_who_changed_what_and_there_is_no_delete(): void
    {
        $this->actingAs($this->validator);
        Livewire::test(SwsData::class)->set('record', $this->record())->call('save');
        $id = SwsStore::table()->value('id');

        Livewire::test(SwsData::class)
            ->call('edit', $id)
            ->assertSet('tab', 'entry')
            ->set('record.street_name', 'Kalayaan')
            ->call('save')
            ->assertHasNoErrors()
            ->call('showHistory', $id)
            ->assertSee('Record history')
            ->assertSee('KALAYAAN');

        $this->assertSame(['street_name' => ['MAGINHAWA', 'KALAYAAN']], SwsChange::where('action', 'updated')->sole()->changes);
        $this->assertFalse(method_exists(SwsData::class, 'delete'));
    }

    public function test_a_csv_upload_is_checked_then_imported(): void
    {
        $this->actingAs($this->validator);
        Livewire::test(SwsData::class)->set('record', $this->record())->call('save');

        $csv = $this->csv(
            $this->record(['street_name' => 'Kalayaan']),                   // update
            $this->record(['sws_member_no' => 'SWS_002', 'firstname' => 'Ana']), // new
            $this->record(['sws_member_no' => 'SWS_003', 'longitude' => 'east']), // problem
            $this->record(['firstname' => 'Again']),                        // duplicate of line 2
        );

        $component = Livewire::test(SwsData::class)
            ->call('showTab', 'upload')
            ->set('file', UploadedFile::fake()->createWithContent('sws.csv', $csv))
            ->assertSet('upload.valid', 2)
            ->assertSet('upload.inserts', 1)
            ->assertSet('upload.updates', 1)
            ->assertSet('upload.problemCount', 2)
            ->assertSee('LONGITUDE');

        $this->assertSame(1, SwsStore::table()->count(), 'nothing is saved before import');
        $component->call('import');

        $this->assertSame(2, SwsStore::table()->count());
        $this->assertSame('KALAYAAN', SwsStore::table()->where('sws_member_no', 'SWS_001')->value('street_name'));
        $upload = SwsActivity::where('action', 'imported')->sole();
        $this->assertSame([1, 1], [$upload->inserted, $upload->updated]);
        $this->assertSame(2, SwsChange::where('sws_activity_id', $upload->id)->count());
    }

    public function test_the_sws_excel_file_can_be_uploaded_directly(): void
    {
        $this->actingAs($this->validator);
        $path = $this->xlsx($this->record(['suffix' => '', 'contact_number' => '9066963148', 'idp_age' => '']));

        Livewire::test(SwsData::class)
            ->set('file', UploadedFile::fake()->createWithContent('SWS_TEMPLATE.xlsx', file_get_contents($path)))
            ->assertHasNoErrors()
            ->assertSet('upload.valid', 1)
            ->call('import');

        $row = SwsStore::table()->sole();
        $this->assertSame(['SWS_001', '09066963148', '14.6427441299736', null], [$row->sws_member_no, $row->contact_number, $row->latitude, $row->idp_age]);
        @unlink($path);
    }

    public function test_uploads_report_through_alerts(): void
    {
        $this->actingAs($this->validator);

        // A clean file: a success alert that waits for OK, then a confirmed import alert
        Livewire::test(SwsData::class)
            ->set('file', UploadedFile::fake()->createWithContent('sws.csv', $this->csv($this->record())))
            ->assertDispatched('toast', fn ($name, $p) => $p['type'] === 'success' && $p['title'] === 'File checked' && ($p['persist'] ?? false))
            ->call('import')
            ->assertDispatched('toast', fn ($name, $p) => $p['title'] === 'Import complete' && str_contains($p['message'], 'Added 1 new'));

        // Some rows bad: a warning
        Livewire::test(SwsData::class)
            ->set('file', UploadedFile::fake()->createWithContent('sws.csv', $this->csv($this->record(['sws_member_no' => 'SWS_009']), $this->record(['sws_member_no' => 'SWS_010', 'latitude' => 'north']))))
            ->assertDispatched('toast', fn ($name, $p) => $p['type'] === 'warning');

        // Not usable at all: an error alert as well as the message under the file box
        Livewire::test(SwsData::class)
            ->set('file', UploadedFile::fake()->create('notes.pdf', 5, 'application/pdf'))
            ->assertHasErrors('file')
            ->assertDispatched('toast', fn ($name, $p) => $p['type'] === 'error' && $p['title'] === "This file can't be used");
    }

    public function test_a_file_without_the_sws_headers_is_refused(): void
    {
        $this->actingAs($this->validator);

        Livewire::test(SwsData::class)
            ->call('showTab', 'upload')
            ->set('file', UploadedFile::fake()->createWithContent('sws.csv', "NAME,AGE\nJuan,30"))
            ->assertHasErrors('file')
            ->assertSee('The header row is missing');
    }

    public function test_the_map_places_households_by_their_coordinates(): void
    {
        $this->actingAs($this->validator);
        $ids = [];
        foreach ([
            ['SWS_001', '14.6427441', '121.0607261', 'QUEZON CITY'],
            ['SWS_002', '14.5995', '120.9842', 'MANILA'],
            ['SWS_003', '', '', 'MANILA'],                // no coordinates
            ['SWS_004', '121.0607261', '14.6427441', 'MANILA'], // swapped - lands outside the Philippines
        ] as [$no, $lat, $lng, $city]) {
            $ids[$no] = SwsStore::table()->insertGetId($this->record(['sws_member_no' => $no, 'latitude' => $lat, 'longitude' => $lng, 'city' => $city, 'idp_age' => null]));
        }

        $map = Livewire::test(SwsData::class)->call('showTab', 'map')->assertSee('View map')->viewData('map');
        $this->assertSame([$ids['SWS_001'], $ids['SWS_002'], $ids['SWS_004']], array_column($map['points'], 0));
        $this->assertSame([4, 1, 1], [$map['matching'], $map['missing'], $map['outside']]);
        $this->assertSame(14.6427441, $map['points'][0][1]);

        // The map follows the same filters as the data view
        $manila = Livewire::test(SwsData::class)->call('showTab', 'map')->set('city', 'MANILA')->viewData('map');
        $this->assertCount(2, $manila['points']);

        // "List them" goes back to the table, narrowed to records without coordinates
        $missing = Livewire::test(SwsData::class)->call('showTab', 'map')->call('showMissingCoordinates')->assertSet('tab', 'data');
        $this->assertSame(['SWS_003'], collect($missing->viewData('rows')->items())->pluck('sws_member_no')->all());

        // The pin on a table row opens the map on that household
        Livewire::test(SwsData::class)
            ->set('city', 'MANILA')
            ->call('showOnMap', $ids['SWS_001'])
            ->assertSet('tab', 'map')
            ->assertSet('city', '')
            ->assertSet('focusId', $ids['SWS_001']);
    }

    /** The live server's sws_data, on the in-memory test copy of mysql_live (never the real one). */
    private function liveTable(): \Illuminate\Database\Query\Builder
    {
        $live = SwsStore::live();
        $this->assertSame('sqlite', $live->getDriverName(), 'tests must not reach the real live server');
        if (!$live->getSchemaBuilder()->hasTable('sws_data')) {
            $live->getSchemaBuilder()->create('sws_data', fn ($t) => \App\Support\Survey::swsDataColumns($t));
        }

        return $live->table('sws_data');
    }

    public function test_transmitting_sends_pending_records_to_the_live_server(): void
    {
        $this->actingAs($this->validator);
        $live = $this->liveTable();
        // The live server already has SWS_001, with older details
        $live->insert($this->record(['sws_member_no' => 'SWS_001', 'lastname' => 'OLD NAME', 'idp_age' => null]));

        foreach (['SWS_001', 'SWS_002', 'SWS_003'] as $no) {
            Livewire::test(SwsData::class)->set('record', $this->record(['sws_member_no' => $no]))->call('save');
        }
        $this->assertSame(3, SwsStore::pending(SwsStore::table())->count());

        Livewire::test(SwsData::class)
            ->assertViewHas('pendingAll', 3)
            ->call('transmit')
            ->assertDispatched('toast', fn ($n, $p) => $p['title'] === 'Data transmitted' && str_contains($p['message'], '3 SWS record(s)') && str_contains($p['message'], '2 new, 1 updated'))
            ->assertViewHas('pendingAll', 0);

        $this->assertSame(3, $live->count(), 'SWS_001 is updated on the live server, not duplicated');
        $this->assertSame('DELA CRUZ', $live->where('sws_member_no', 'SWS_001')->value('lastname'));
        $this->assertSame(3, SwsStore::table()->where('transmitted', 'yes')->whereNotNull('transmitted_at')->count());
        $this->assertSame([2, 1], [SwsActivity::where('action', 'transmitted')->sole()->inserted, SwsActivity::where('action', 'transmitted')->sole()->updated]);
        $this->assertSame(3, SwsChange::where('action', 'transmitted')->count());

        // Nothing left: a second press says so and sends nothing
        Livewire::test(SwsData::class)->call('transmit')->assertDispatched('toast', fn ($n, $p) => $p['title'] === 'Nothing to transmit');
    }

    public function test_a_changed_record_must_be_transmitted_again(): void
    {
        $this->actingAs($this->validator);
        $live = $this->liveTable();
        Livewire::test(SwsData::class)->set('record', $this->record())->call('save');
        Livewire::test(SwsData::class)->call('transmit');
        $id = SwsStore::table()->value('id');

        // Saving without a change keeps it transmitted
        Livewire::test(SwsData::class)->call('edit', $id)->call('save');
        $this->assertSame('yes', SwsStore::table()->value('transmitted'));

        // A real change puts it back in the queue...
        Livewire::test(SwsData::class)->call('edit', $id)->set('record.street_name', 'Kalayaan')->call('save');
        $this->assertSame('no', SwsStore::table()->value('transmitted'));
        $this->assertSame(1, Livewire::test(SwsData::class)->set('transmitted', 'no')->viewData('rows')->total());

        // ...and the next transmission updates the live copy
        Livewire::test(SwsData::class)->call('transmit');
        $this->assertSame('KALAYAAN', $live->where('sws_member_no', 'SWS_001')->value('street_name'));
        $this->assertSame(1, $live->count());

        // An upload that changes it does the same
        Livewire::test(SwsData::class)->set('file', UploadedFile::fake()->createWithContent('sws.csv', $this->csv($this->record(['street_name' => 'Kamias']))))->call('import');
        $this->assertSame('no', SwsStore::table()->value('transmitted'));
    }

    public function test_only_records_under_the_filters_are_transmitted(): void
    {
        $this->actingAs($this->validator);
        $live = $this->liveTable();
        Livewire::test(SwsData::class)->set('record', $this->record(['sws_member_no' => 'SWS_001', 'city' => 'Quezon City']))->call('save');
        Livewire::test(SwsData::class)->set('record', $this->record(['sws_member_no' => 'SWS_002', 'city' => 'Manila']))->call('save');

        Livewire::test(SwsData::class)
            ->set('city', 'MANILA')
            ->assertViewHas('pendingHere', 1)
            ->assertViewHas('pendingAll', 2)
            ->call('transmit');

        $this->assertSame(['SWS_002'], $live->pluck('sws_member_no')->all());
        $this->assertSame(['SWS_002'], SwsStore::table()->where('transmitted', 'yes')->pluck('sws_member_no')->all());
    }

    public function test_a_failed_transmission_marks_nothing(): void
    {
        $this->actingAs($this->validator);
        // No live sws_data table: the live server refuses the data
        Livewire::test(SwsData::class)->set('record', $this->record())->call('save');

        Livewire::test(SwsData::class)
            ->call('transmit')
            ->assertDispatched('toast', fn ($n, $p) => $p['type'] === 'error' && $p['title'] === 'Transmission failed');

        $this->assertSame('no', SwsStore::table()->value('transmitted'));
        $this->assertSame(0, SwsActivity::where('action', 'transmitted')->count());
    }

    public function test_the_data_view_filters_sorts_pages_and_exports(): void
    {
        $this->actingAs($this->validator);
        foreach (range(1, 30) as $i) {
            SwsStore::table()->insert($this->record([
                'sws_member_no' => sprintf('SWS_%03d', $i), 'lastname' => 'PERSON ' . $i,
                'city' => $i <= 20 ? 'QUEZON CITY' : 'MANILA', 'barangay' => 'BARANGAY 1', 'district' => 'DISTRICT 1',
            ]));
        }

        $component = Livewire::test(SwsData::class)->set('perPage', 25);
        $this->assertSame(30, $component->viewData('rows')->total());
        $this->assertCount(25, $component->viewData('rows')->items());

        $component->set('city', 'MANILA');
        $this->assertSame(10, $component->viewData('rows')->total());

        $component->call('sortBy', 'sws_member_no');
        $this->assertSame('SWS_021', $component->viewData('rows')->items()[0]->sws_member_no);

        $component->set('search', 'PERSON 25');
        $this->assertSame(1, $component->viewData('rows')->total());

        $export = $this->get('/sws/export?city=MANILA')->assertOk()->streamedContent();
        $lines = array_values(array_filter(explode("\n", $export)));
        $this->assertCount(11, $lines);
        $this->assertStringStartsWith("\xEF\xBB\xBFCITY,DISTRICT,BARANGAY", $lines[0]);
    }
}
