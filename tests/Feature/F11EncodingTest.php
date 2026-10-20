<?php

namespace Tests\Feature;

use App\Livewire\F11Encoding;
use App\Models\F11Activity;
use App\Models\F11Change;
use App\Models\User;
use App\Support\F11Record;
use App\Support\Survey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\Concerns\UsesSurveyDatabase;
use Tests\TestCase;

class F11EncodingTest extends TestCase
{
    use RefreshDatabase, UsesSurveyDatabase;

    private User $validator;

    private array $area;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSurveyDatabase();
        $this->area = $this->seedArea();
        $this->seedHousehold($this->area, 'SUN-0005', '6722');
        $this->validator = User::factory()->validator()->create();
        // Encoding and upload are switched off for now; these tests keep the code working for when they return
        F11Encoding::$editing = true;
    }

    protected function tearDown(): void
    {
        F11Encoding::$editing = false;
        parent::tearDown();
    }

    public function test_for_now_the_page_is_view_only(): void
    {
        F11Encoding::$editing = false;
        Survey::db()->table('f11')->insert([...$this->member(), 'surname' => 'BONCAROS', 'givenname' => 'JOYLYN', 'member_no' => '01']);
        $id = Survey::db()->table('f11')->value('id');
        $this->actingAs($this->validator);

        Livewire::test(F11Encoding::class)
            ->assertSee('F11 Membership')
            ->assertSee('BONCAROS, JOYLYN')
            ->assertSee('History')
            ->assertDontSee('CSV template')
            ->assertDontSee('Upload CSV')
            ->assertDontSee('Recent F11 activity')
            ->assertDontSee('Added / edited by')
            ->call('showTab', 'encode')->assertSet('tab', 'records')
            ->call('edit', $id)->assertSet('editingId', null)
            ->set('member', $this->member(['givenname' => 'Changed']))->call('save')
            ->call('showHistory', $id)->assertSet('historyId', $id);

        $this->assertSame('JOYLYN', Survey::db()->table('f11')->where('id', $id)->value('givenname'));
    }

    private function member(array $overrides = []): array
    {
        return [
            ...$this->area,
            'identifier1' => 'SUN-0005', 'identifier2' => '6722', 'identifier3' => '',
            'sws_member_no' => '6722-2', 'member_category' => 'WRA (15-49 years old)', 'member_no' => '1',
            'surname' => 'Boncaros', 'givenname' => 'Joylyn', 'birthdate' => '1990-08-27', 'sex' => 'FEMALE',
            'csc' => '', 'psc' => 'Lactating', 'foodrecall_day' => '1', 'reference_date' => '2026-05-01',
            'interview_status' => '1', 'interview_status_others' => '', 'refuse_remarks' => '',
            ...$overrides,
        ];
    }

    public function test_clients_cannot_open_f11(): void
    {
        $client = User::factory()->create();

        $this->actingAs($client)->get('/f11')->assertForbidden();
        $this->actingAs($client)->get('/f11/template')->assertForbidden();
    }

    public function test_the_template_is_the_header_row(): void
    {
        $response = $this->actingAs($this->validator)->get('/f11/template')->assertOk();

        $this->assertStringContainsString('filename="f11_template.csv"', $response->headers->get('Content-Disposition'));
        $this->assertSame(F11Record::COLUMNS, str_getcsv(trim(substr($response->getContent(), 3))));
    }

    public function test_a_validator_encodes_a_member(): void
    {
        $this->actingAs($this->validator);

        Livewire::test(F11Encoding::class)
            ->set('member', $this->member())
            ->call('save')
            ->assertHasNoErrors();

        $row = Survey::db()->table('f11')->first();
        $this->assertSame('BONCAROS', $row->surname);
        $this->assertSame('01', $row->member_no);
        $this->assertSame('8/27/1990', $row->birthdate);
        $this->assertSame('05-01-2026', $row->reference_date);
        $this->assertEquals(35, $row->age_years);
        $this->assertSame('created', F11Activity::sole()->action);
    }

    public function test_the_same_member_cannot_be_encoded_twice(): void
    {
        $this->actingAs($this->validator);
        Livewire::test(F11Encoding::class)->set('member', $this->member())->call('save');

        Livewire::test(F11Encoding::class)
            ->set('member', $this->member(['surname' => 'Other']))
            ->call('save')
            ->assertHasErrors('member.member_no');

        $this->assertSame(1, Survey::db()->table('f11')->count());
    }

    public function test_an_unknown_area_is_rejected(): void
    {
        $this->actingAs($this->validator);

        Livewire::test(F11Encoding::class)
            ->set('member', $this->member(['barangay' => 'Nowhere']))
            ->call('save')
            ->assertHasErrors('member.barangay');
    }

    public function test_a_member_is_edited_but_cannot_be_deleted(): void
    {
        $this->actingAs($this->validator);
        Livewire::test(F11Encoding::class)->set('member', $this->member())->call('save');
        $id = Survey::db()->table('f11')->value('id');

        Livewire::test(F11Encoding::class)
            ->call('edit', $id)
            ->assertSet('tab', 'encode')
            ->assertSet('member.birthdate', '1990-08-27')
            ->set('member.givenname', 'Joy')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('JOY', Survey::db()->table('f11')->value('givenname'));

        $this->assertSame(['created', 'updated'], F11Activity::orderBy('id')->pluck('action')->all());

        // Records are corrected, never removed - there is no delete action to call
        $this->assertFalse(method_exists(F11Encoding::class, 'delete'));
        Livewire::test(F11Encoding::class)->assertDontSeeHtml('wire:click="delete(');
        $this->assertSame(1, Survey::db()->table('f11')->count());
    }

    public function test_a_csv_upload_is_checked_then_imported(): void
    {
        $this->actingAs($this->validator);
        Livewire::test(F11Encoding::class)->set('member', $this->member())->call('save');

        $rows = [
            F11Record::COLUMNS,
            // Existing member - an update
            array_values($this->member(['givenname' => 'Joylyn Mae', 'birthdate' => '8/27/1990', 'reference_date' => '05-01-2026'])),
            // New child
            array_values($this->member(['member_no' => '2', 'givenname' => 'Baby', 'member_category' => 'CU5 (0-23 months old)', 'birthdate' => '2024-08-24', 'psc' => '', 'sex' => 'M'])),
            // Problem row
            array_values($this->member(['member_no' => '3', 'sex' => '?'])),
        ];
        $csv = implode("\n", array_map(fn ($r) => implode(',', array_map(fn ($v) => '"' . $v . '"', $r)), $rows));

        $component = Livewire::test(F11Encoding::class)
            ->call('showTab', 'upload')
            ->set('file', UploadedFile::fake()->createWithContent('f11.csv', $csv))
            ->assertHasNoErrors()
            ->assertSet('upload.valid', 2)
            ->assertSet('upload.inserts', 1)
            ->assertSet('upload.updates', 1)
            ->assertSet('upload.problemCount', 1)
            ->assertSee('The sex must be MALE or FEMALE.');

        $this->assertSame(1, Survey::db()->table('f11')->count(), 'nothing is saved before import');

        $component->call('import')->assertSet('upload', null);

        $this->assertSame(2, Survey::db()->table('f11')->count());
        $this->assertSame('JOYLYN MAE', Survey::db()->table('f11')->where('member_no', '01')->value('givenname'));
        $this->assertEquals(20, Survey::db()->table('f11')->where('member_no', '02')->value('age_months'));

        $import = F11Activity::where('action', 'imported')->sole();
        $this->assertSame([1, 1], [$import->inserted, $import->updated]);
    }

    public function test_each_change_records_who_made_it_and_what_changed(): void
    {
        $this->actingAs($this->validator);
        Livewire::test(F11Encoding::class)->set('member', $this->member())->call('save');
        $id = Survey::db()->table('f11')->value('id');

        $other = User::factory()->validator()->create(['first_name' => 'Maria', 'last_name' => 'Santos', 'middle_name' => null]);
        $this->actingAs($other);
        Livewire::test(F11Encoding::class)->call('edit', $id)->set('member.givenname', 'Joy')->set('member.sex', 'MALE')->call('save');
        // Saving without a change leaves no history line
        Livewire::test(F11Encoding::class)->call('edit', $id)->call('save');

        $changes = F11Change::where('f11_id', $id)->orderBy('id')->get();
        $this->assertSame(['created', 'updated'], $changes->pluck('action')->all());
        $this->assertSame([$this->validator->id, $other->id], $changes->pluck('user_id')->all());
        $this->assertSame(['givenname' => ['JOYLYN', 'JOY'], 'sex' => ['FEMALE', 'MALE']], $changes[1]->changes);
        $this->assertSame('form', $changes[1]->source);

        Livewire::test(F11Encoding::class)
            ->assertSee('edited by')
            ->assertSee('Maria Santos')
            ->call('showHistory', $id)
            ->assertSee('Member history')
            ->assertSee('JOYLYN');

        // Only what Maria touched
        $this->assertSame(1, Livewire::test(F11Encoding::class)->set('changedBy', (string) $other->id)->viewData('records')->total());
        $this->assertSame(0, Livewire::test(F11Encoding::class)->set('changedBy', (string) User::factory()->validator()->create()->id)->viewData('records')->total());
    }

    public function test_uploaded_members_are_traced_to_their_upload(): void
    {
        $this->actingAs($this->validator);
        $csv = implode(',', F11Record::COLUMNS) . "\n" . implode(',', array_values($this->member()));

        Livewire::test(F11Encoding::class)
            ->set('file', UploadedFile::fake()->createWithContent('batch-a.csv', $csv))
            ->call('import');

        $upload = F11Activity::where('action', 'imported')->sole();
        $change = F11Change::sole();
        $this->assertSame(['created', 'upload', $upload->id, $this->validator->id], [$change->action, $change->source, $change->f11_activity_id, $change->user_id]);

        Livewire::test(F11Encoding::class)
            ->call('showUpload', $upload->id)
            ->assertSet('uploadBatch', (string) $upload->id)
            ->assertSee('BONCAROS')
            ->call('showHistory', $change->f11_id)
            ->assertSee('via upload batch-a.csv');
    }

    public function test_a_chosen_file_is_checked_without_another_click(): void
    {
        $this->actingAs($this->validator);
        $csv = implode(',', F11Record::COLUMNS) . "\n" . implode(',', array_values($this->member()));

        Livewire::test(F11Encoding::class)
            ->call('showTab', 'upload')
            ->set('file', UploadedFile::fake()->createWithContent('batch.csv', $csv))
            ->assertSet('upload.valid', 1)
            ->assertSee('Checked: batch.csv')
            ->assertSee('Import 1 member(s)');
    }

    public function test_a_wrong_or_oversized_file_says_why(): void
    {
        $this->actingAs($this->validator);

        Livewire::test(F11Encoding::class)
            ->call('showTab', 'upload')
            ->set('file', UploadedFile::fake()->create('members.pdf', 10, 'application/pdf'))
            ->assertHasErrors('file')
            ->assertSee('This is not a CSV file.')
            ->set('file', UploadedFile::fake()->create('huge.csv', \App\Support\F11Store::maxUploadKb() + 100, 'text/csv'))
            ->assertSee('Split it into smaller files.');
    }

    public function test_a_file_without_the_template_header_is_refused(): void
    {
        $this->actingAs($this->validator);

        Livewire::test(F11Encoding::class)
            ->set('file', UploadedFile::fake()->createWithContent('f11.csv', "name,age\nJuan,30"))
            ->assertHasErrors('file')
            ->assertSet('upload', null);
    }
}
