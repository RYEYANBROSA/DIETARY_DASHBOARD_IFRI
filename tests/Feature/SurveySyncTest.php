<?php

namespace Tests\Feature;

use App\Livewire\SyncData;
use App\Models\SurveySyncRun;
use App\Models\User;
use App\Support\Modules;
use App\Support\Survey;
use App\Support\SurveySync;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\UsesSurveyDatabase;
use Tests\TestCase;

class SurveySyncTest extends TestCase
{
    use RefreshDatabase, UsesSurveyDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSurveyDatabase();
        $this->admin = User::factory()->administrator()->create();

        // The live server (in-memory SQLite here, see phpunit.xml) with its survey tables
        $schema = DB::connection(SurveySync::CONNECTION)->getSchemaBuilder();
        foreach (Survey::definitions() as $table => $definition) {
            $schema->create($table, $definition);
        }
    }

    private function live(string $table)
    {
        return DB::connection(SurveySync::CONNECTION)->table($table);
    }

    private function liveMember(int $id, string $memberNo, string $updated, array $extra = []): void
    {
        $this->live('f11')->insert([
            'id' => $id, 'province' => 'QUEZON CITY', 'municipality' => 'DISTRICT 1', 'barangay' => 'BOTOCAN',
            'identifier1' => 'QC001', 'identifier2' => 'QC001', 'identifier3' => '', 'member_no' => $memberNo,
            'surname' => 'BORDA', 'givenname' => "MEMBER {$memberNo}", 'age_years' => '30',
            'date_transmitted' => $updated, 'date_updated' => $updated, ...$extra,
        ]);
    }

    public function test_only_administrators_get_sync_data(): void
    {
        $validator = User::factory()->validator()->create();
        $this->assertFalse($validator->canAccess(Modules::SYNC));
        $this->actingAs($validator)->get('/sync')->assertForbidden();

        // Not even when the module is ticked on for them
        $client = User::factory()->create(['modules' => Modules::keys()]);
        $this->actingAs($client)->get('/sync')->assertForbidden();

        $this->actingAs($this->admin)->get('/sync')->assertOk()->assertSee('Sync Data')->assertSee('f72_interview_status');
    }

    private function local(): array
    {
        $rows = Survey::db()->table('f11')->get()->mapWithKeys(fn ($r) => [$r->province . '#' . $r->member_no => $r->givenname])->all();
        ksort($rows);

        return $rows;
    }

    public function test_a_fetch_only_adds_rows_that_are_not_here_yet(): void
    {
        $this->liveMember(1, '01', '2026-10-20 08:00:00');
        $this->liveMember(2, '02', '2026-10-20 08:00:00');
        $this->liveMember(5, '03', '2026-10-20 08:00:00');
        // A local row the live server doesn't have (older data): kept
        Survey::db()->table('f11')->insert(['province' => 'X', 'member_no' => '01', 'givenname' => 'OLD']);

        $run = SurveySync::fetch('f11', $this->admin);
        $this->assertSame('ok', $run->status, (string) $run->message);
        $this->assertSame([3, 1, 3, 4, 'add-only'], [$run->fetched, $run->retained, $run->live_count, $run->local_count, $run->mode]);
        $this->assertSame(['QUEZON CITY#01' => 'MEMBER 01', 'QUEZON CITY#02' => 'MEMBER 02', 'QUEZON CITY#03' => 'MEMBER 03', 'X#01' => 'OLD'], $this->local());

        // On the live server: member 2 is corrected, member 3 deleted, member 4 added
        $this->live('f11')->where('id', 2)->update(['givenname' => 'CORRECTED', 'date_updated' => '2026-10-21 09:00:00']);
        $this->live('f11')->where('id', 5)->delete();
        $this->liveMember(6, '04', '2026-10-21 09:00:00');

        $run = SurveySync::fetch('f11');
        $this->assertSame('ok', $run->status, (string) $run->message);
        // Only member 4 is added: member 2 is NOT updated, member 3 (deleted on live) and the older row stay
        $this->assertSame([
            'QUEZON CITY#01' => 'MEMBER 01', 'QUEZON CITY#02' => 'MEMBER 02', 'QUEZON CITY#03' => 'MEMBER 03', 'QUEZON CITY#04' => 'MEMBER 04', 'X#01' => 'OLD',
        ], $this->local());
        $this->assertSame([1, 2, 3, 5], [$run->fetched, $run->retained, (int) $run->live_count, (int) $run->local_count]);

        // Nothing new: nothing added
        $this->assertSame(0, SurveySync::fetch('f11')->fetched);
    }

    public function test_a_row_removed_here_is_added_back_from_the_live_server(): void
    {
        $this->liveMember(1, '01', '2026-10-20 08:00:00');
        SurveySync::fetch('f11');
        Survey::db()->table('f11')->delete();

        $this->assertSame(1, SurveySync::fetch('f11')->fetched);
        $this->assertSame(['QUEZON CITY#01' => 'MEMBER 01'], $this->local());
    }

    public function test_an_older_local_table_is_rebuilt_in_the_live_layout_keeping_its_rows(): void
    {
        // f11 in an older layout: a column the live server doesn't have, an integer age, no date stamps
        $schema = Survey::db()->getSchemaBuilder();
        $schema->drop('f11');
        $schema->create('f11', function (Blueprint $t) {
            $t->increments('id');
            foreach (['province', 'municipality', 'barangay', 'identifier1', 'identifier2', 'identifier3', 'member_no', 'surname', 'old_column'] as $c) {
                $t->string($c, 100)->nullable();
            }
            $t->integer('age_years')->nullable();
            $t->unique(['province', 'member_no'], 'f11_member_unique');
        });
        Survey::db()->table('f11')->insert(['id' => 7, 'province' => 'P', 'member_no' => '01', 'surname' => 'KEPT', 'age_years' => 40, 'old_column' => 'x']);
        Survey::flush();

        $this->assertTrue(Survey::differs('f11'));
        $this->assertSame([1, 1], Survey::rebuild('f11'));
        $this->assertFalse(Survey::differs('f11'));
        $this->assertSame(Survey::layoutColumns('f11'), Survey::columns('f11'));
        $row = Survey::db()->table('f11')->first();
        $this->assertSame([7, 'KEPT', '40'], [(int) $row->id, $row->surname, (string) $row->age_years]);

        // A fetch does the same on its own before copying
        $schema->drop('f11');
        $schema->create('f11', fn (Blueprint $t) => $t->increments('id'));
        Survey::flush();
        $this->liveMember(1, '01', '2026-10-20 08:00:00');
        $this->assertSame('ok', SurveySync::fetch('f11')->status);
        $this->assertSame('MEMBER 01', Survey::db()->table('f11')->value('givenname'));
    }

    public function test_the_page_fetches_one_table_or_all_and_logs_who(): void
    {
        $this->liveMember(1, '01', '2026-10-20 08:00:00');
        $this->live('localarea')->insert(['province' => 'QUEZON CITY', 'municipality' => 'DISTRICT 1', 'barangay' => 'BOTOCAN', 'status' => '1']);
        $this->actingAs($this->admin);

        Livewire::test(SyncData::class)
            ->assertSee('Never fetched')
            ->call('fetch', 'f11')
            ->assertDispatched('toast', fn ($n, $p) => $p['type'] === 'success' && str_contains($p['message'], '1 new row(s) added'))
            ->call('fetchAll')
            ->assertDispatched('toast', fn ($n, $p) => $p['title'] === 'All tables fetched');

        $this->assertSame(1, Survey::db()->table('localarea')->count());
        $this->assertSame($this->admin->id, SurveySyncRun::where('table', 'f11')->first()->user_id);
        $this->assertSame(1 + count(SurveySync::TABLES), SurveySyncRun::where('status', 'ok')->count());
    }

    public function test_the_command_is_the_scheduled_auto_fetch(): void
    {
        $this->liveMember(1, '01', '2026-10-20 08:00:00');

        $this->artisan('survey:sync', ['--auto' => true])->assertSuccessful();
        $this->assertSame(count(SurveySync::TABLES), SurveySyncRun::where('trigger', 'auto')->whereNull('user_id')->count());
        $this->assertSame(1, Survey::db()->table('f11')->count());

        $this->artisan('survey:sync', ['table' => 'nope'])->assertFailed();

        // Scheduled every AUTO_MINUTES
        $event = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'survey:sync'));
        $this->assertNotNull($event);
        $this->assertSame('*/' . SurveySync::AUTO_MINUTES . ' * * * *', $event->expression);
    }

    public function test_a_table_missing_on_the_live_server_fails_and_says_why(): void
    {
        DB::connection(SurveySync::CONNECTION)->getSchemaBuilder()->drop('f72');

        $run = SurveySync::fetch('f72');
        $this->assertSame('failed', $run->status);
        $this->assertSame('The live server has no f72 table.', $run->message);
    }
}
