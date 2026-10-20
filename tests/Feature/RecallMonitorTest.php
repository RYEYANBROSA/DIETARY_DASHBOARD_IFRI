<?php

namespace Tests\Feature;

use App\Livewire\Dashboard;
use App\Models\User;
use App\Support\RecallMonitor;
use App\Support\Survey;
use App\Support\SwsStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\UsesSurveyDatabase;
use Tests\TestCase;

class RecallMonitorTest extends TestCase
{
    use RefreshDatabase, UsesSurveyDatabase;

    private const BLANK = ['from' => '', 'to' => '', 'province' => '', 'municipality' => '', 'barangay' => '', 'researcher' => '', 'day' => ''];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSurveyDatabase();

        // As on the live server: f72_interview_status
        Survey::db()->getSchemaBuilder()->create(RecallMonitor::TABLE, function ($t) {
            $t->increments('id');
            foreach (['province', 'municipality', 'barangay', 'identifier1', 'identifier2', 'identifier3', 'sws_member_no', 'member_no', 'foodrecall_day', 'interview_status', 'interview_status_others', 'researcher'] as $c) {
                $t->string($c, 100)->nullable();
            }
            $t->dateTime('date_transmitted')->nullable();
            $t->dateTime('date_updated')->nullable();
        });

        // Targets: 10 SWS respondents, 6 in Barangay 1 (District 1) and 4 in Paltok (District 1)
        foreach (range(1, 10) as $i) {
            SwsStore::table()->insert([
                'city' => 'QUEZON CITY', 'district' => 'DISTRICT 1', 'barangay' => $i <= 6 ? 'BARANGAY 1' : 'PALTOK',
                'identifier1' => "QC{$i}", 'identifier2' => '', 'identifier3' => '', 'sws_member_no' => "SWS_{$i}",
            ]);
        }
    }

    private function recall(int $member, string $day, string $status, string $researcher, string $date, string $barangay = 'BARANGAY 1', ?string $updated = null): void
    {
        Survey::db()->table(RecallMonitor::TABLE)->insert([
            'province' => 'QUEZON CITY', 'municipality' => 'DISTRICT 1', 'barangay' => $barangay,
            'identifier1' => "QC{$member}", 'identifier2' => '', 'identifier3' => '', 'sws_member_no' => "SWS_{$member}", 'member_no' => '01',
            'foodrecall_day' => $day, 'interview_status' => $status, 'researcher' => $researcher,
            'date_transmitted' => "{$date} 09:00:00", 'date_updated' => $updated ?? "{$date} 09:00:00",
        ]);
    }

    private function seedRecalls(): void
    {
        // Day 1: members 1-4 completed (status 1, 1, 2, 1), 5 refused (3), 6 refused (4) - Barangay 1
        $this->recall(1, '1', '1', 'DR_001', '2026-10-14');
        $this->recall(2, '1', '1', 'DR_001', '2026-10-14');
        $this->recall(3, '1', '2', 'DR_002', '2026-10-15');
        $this->recall(4, '1', '1', 'DR_002', '2026-10-15');
        $this->recall(5, '1', '3', 'DR_001', '2026-10-15');
        $this->recall(6, '1', '4', 'DR_002', '2026-10-15');
        // Member 7 (Paltok): first sent as refused, re-sent later as completed - counts once, as completed
        $this->recall(7, '1', '3', 'DR_002', '2026-10-15', 'PALTOK', '2026-10-15 09:00:00');
        $this->recall(7, '1', '1', 'DR_002', '2026-10-16', 'PALTOK', '2026-10-16 10:00:00');
        // Day 2: members 1 and 2 completed, 3 refused
        $this->recall(1, '2', '1', 'DR_001', '2026-10-16');
        $this->recall(2, '2', '2', 'DR_001', '2026-10-16');
        $this->recall(3, '2', '5', 'DR_002', '2026-10-16');
    }

    public function test_completed_is_status_1_or_2_and_refused_is_3_and_above(): void
    {
        $this->assertSame('completed', RecallMonitor::outcome('1'));
        $this->assertSame('completed', RecallMonitor::outcome(2));
        $this->assertSame('refused', RecallMonitor::outcome('3'));
        $this->assertSame('refused', RecallMonitor::outcome('9'));
        $this->assertSame('other', RecallMonitor::outcome(''));
        $this->assertSame('other', RecallMonitor::outcome(null));
    }

    public function test_rates_are_out_of_the_sws_respondents_per_recall_day(): void
    {
        $this->seedRecalls();
        $r = RecallMonitor::report(self::BLANK);

        $this->assertSame(10, $r['target']);
        $this->assertSame(10, $r['recalls'], 'the re-sent recall counts once');
        // Day 1: completed 1,2,3,4,7 = 5 of 10; refused 5,6 = 2; not yet 3
        $this->assertSame([5, 2, 3, 50.0, 20.0], [$r['days']['1']['completed'], $r['days']['1']['refused'], $r['days']['1']['notYet'], $r['days']['1']['completion'], $r['days']['1']['refusal']]);
        // Day 2: completed 1,2 = 2; refused 3 = 1
        $this->assertSame([2, 1, 20.0, 10.0], [$r['days']['2']['completed'], $r['days']['2']['refused'], $r['days']['2']['completion'], $r['days']['2']['refusal']]);
        // 2 of the 5 Day 1 completers have completed Day 2
        $this->assertSame(['done' => 2, 'of' => 5, 'rate' => 40.0], $r['followUp']);
    }

    public function test_filters_narrow_the_figures(): void
    {
        $this->seedRecalls();

        // Area: Barangay 1 has 6 respondents; member 7 (Paltok) drops out
        $b1 = RecallMonitor::report([...self::BLANK, 'province' => 'QUEZON CITY', 'municipality' => 'DISTRICT 1', 'barangay' => 'BARANGAY 1']);
        $this->assertSame([6, 4, 66.7], [$b1['target'], $b1['days']['1']['completed'], $b1['days']['1']['completion']]);

        // Researcher: DR_001 completed members 1 and 2 on Day 1, refused 5
        $dr1 = RecallMonitor::report([...self::BLANK, 'researcher' => 'DR_001']);
        $this->assertSame([2, 1], [$dr1['days']['1']['completed'], $dr1['days']['1']['refused']]);

        // Date transmitted: only Oct 14
        $oct14 = RecallMonitor::report([...self::BLANK, 'from' => '2026-10-14', 'to' => '2026-10-14']);
        $this->assertSame(2, $oct14['recalls']);

        // Recall day: only Day 2 is reported
        $day2 = RecallMonitor::report([...self::BLANK, 'day' => '2']);
        $this->assertSame([2], array_keys($day2['days']));

        // By area: one level below the filter, with its own target
        $districts = RecallMonitor::report([...self::BLANK, 'province' => 'QUEZON CITY', 'municipality' => 'DISTRICT 1']);
        $this->assertSame(['BARANGAY 1' => 6, 'PALTOK' => 4], collect($districts['areas'])->pluck('target', 'name')->all());
        $this->assertSame(25.0, collect($districts['areas'])->firstWhere('name', 'PALTOK')['1']['completion']);
    }

    public function test_the_dashboard_has_a_monitoring_tab(): void
    {
        $this->seedRecalls();
        $this->actingAs(User::factory()->create());

        Livewire::test(Dashboard::class)
            ->call('showTab', 'recall')
            ->assertSee('24-hr recall monitoring')
            ->assertSee('Day 2 follow-up')
            ->assertSee('50%')
            ->assertSee('DR_001')
            ->call('drillArea', 'QUEZON CITY')
            ->assertSet('province', 'QUEZON CITY')
            ->call('drillArea', 'DISTRICT 1')
            ->assertSet('municipality', 'DISTRICT 1')
            ->assertSee('PALTOK')
            ->call('filterResearcher', 'DR_002')
            ->assertSet('researcher', 'DR_002')
            ->set('province', '')
            ->assertSet('municipality', '');
    }

    public function test_each_household_shows_its_day_1_and_day_2_recall(): void
    {
        $this->seedRecalls();
        $rows = RecallMonitor::households(self::BLANK)->keyBy('sws_member_no');

        // Only households that have transmitted a status: members 1-7 (8-10 have none)
        $this->assertCount(7, $rows);
        $this->assertSame(['QUEZON CITY', 'DISTRICT 1', 'PALTOK'], [$rows['SWS_7']['city'], $rows['SWS_7']['district'], $rows['SWS_7']['barangay']]);
        // Member 1: both days completed
        $this->assertSame('complete', $rows['SWS_1']['completion']);
        $this->assertSame(['status' => '1', 'outcome' => 'completed', 'date' => '2026-10-14', 'researcher' => 'DR_001'], $rows['SWS_1']['days']['1']);
        $this->assertSame('2026-10-16', $rows['SWS_1']['days']['2']['date']);
        // Member 3: Day 1 completed, Day 2 refused (status 5)
        $this->assertSame('refused', $rows['SWS_3']['completion']);
        $this->assertSame('5', $rows['SWS_3']['days']['2']['status']);
        // Member 4: Day 1 only; member 7 re-sent - the latest (completed) counts
        $this->assertSame('partial', $rows['SWS_4']['completion']);
        $this->assertSame('partial', $rows['SWS_7']['completion']);
        // Member 4 has no Day 2 recall yet; members 8-10 sent nothing, so they aren't listed
        $this->assertNull($rows['SWS_4']['days']['2']);
        $this->assertArrayNotHasKey('SWS_9', $rows->all());

        // A status sent for a household not in SWS data is still listed, without a name
        $this->recall(99, '1', '1', 'DR_003', '2026-10-16');
        \Illuminate\Support\Facades\Cache::flush();
        $stray = RecallMonitor::households(self::BLANK)->firstWhere('sws_member_no', 'SWS_99');
        $this->assertFalse($stray['inSws']);
        $this->assertSame('', $stray['lastname']);
        $this->assertTrue(RecallMonitor::households(self::BLANK)->firstWhere('sws_member_no', 'SWS_1')['inSws']);

        // A researcher filter lists only that researcher's households
        $this->assertSame(['SWS_1', 'SWS_2', 'SWS_5'], RecallMonitor::households([...self::BLANK, 'researcher' => 'DR_001'])->pluck('sws_member_no')->all());
        // Search and completion filter
        $this->assertSame(['SWS_4'], RecallMonitor::households(self::BLANK, 'SWS_4')->pluck('sws_member_no')->all());
        $this->assertSame(['SWS_3', 'SWS_5', 'SWS_6'], RecallMonitor::households(self::BLANK, '', 'refused')->pluck('sws_member_no')->all());

        $this->actingAs(User::factory()->create());
        Livewire::test(Dashboard::class)
            ->call('showTab', 'recall')
            ->assertSee('By household')
            ->assertSee('SWS_4')
            ->call('filterHouseholds', 'complete')
            ->assertSet('hhStatus', 'complete')
            ->assertSee('SWS_2')
            ->assertDontSee('SWS_4');
    }

    public function test_rows_are_cached_until_refreshed(): void
    {
        $this->seedRecalls();
        $this->assertSame(10, RecallMonitor::report(self::BLANK)['recalls']);
        $this->assertNotNull(RecallMonitor::fetchedAt());

        // A new recall arrives: the cached copy is still used...
        $this->recall(8, '1', '1', 'DR_003', '2026-10-16', 'PALTOK');
        $this->assertSame(10, RecallMonitor::report(self::BLANK)['recalls']);

        // ...until Refresh reads the server again
        $this->actingAs(User::factory()->create());
        Livewire::test(Dashboard::class)->call('showTab', 'recall')->call('refreshLive')
            ->assertDispatched('toast', fn ($n, $p) => $p['message'] === 'Live recall data refreshed.');
        $this->assertSame(11, RecallMonitor::report(self::BLANK)['recalls']);
    }

    public function test_an_unreachable_server_is_reported_at_once_and_the_overview_still_works(): void
    {
        $this->seedRecalls();
        // As after a failed connection: the server is noted as down for a minute
        \Illuminate\Support\Facades\Cache::put('recall_monitor.' . RecallMonitor::connectionName() . '.down', true, 60);
        $this->assertSame('unreachable', RecallMonitor::state());

        $this->actingAs(User::factory()->create());
        Livewire::test(Dashboard::class)
            ->assertSee("The live server can't be reached right now.", false)
            ->assertSee('SWS households')
            ->call('showTab', 'recall')
            ->assertSee("The live server can't be reached right now.", false)
            ->call('refreshLive')
            ->assertSee('Day 2 follow-up');
    }

    public function test_without_the_table_the_tab_says_so(): void
    {
        Survey::db()->getSchemaBuilder()->drop(RecallMonitor::TABLE);
        $this->actingAs(User::factory()->create());

        Livewire::test(Dashboard::class)->call('showTab', 'recall')->assertSee("The monitoring table (f72_interview_status) isn't there yet.", false);
    }
}
