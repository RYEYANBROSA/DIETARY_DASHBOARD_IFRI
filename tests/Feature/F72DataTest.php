<?php

namespace Tests\Feature;

use App\Livewire\Dashboard;
use App\Livewire\F72Data;
use App\Models\User;
use App\Support\Modules;
use App\Support\Survey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\UsesSurveyDatabase;
use Tests\TestCase;

class F72DataTest extends TestCase
{
    use RefreshDatabase, UsesSurveyDatabase;

    private User $client;

    private array $area;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSurveyDatabase();
        // Clients no longer get F72 by default; an administrator switches it on
        $this->client = User::factory()->create(['modules' => [Modules::DASHBOARD, Modules::F72, Modules::PROFILE]]);

        $this->area = $this->seedArea();
        $covered = $this->seedHousehold($this->area, 'SUN-0005', '6722');
        $this->seedHousehold($this->area, 'SUN-0006', '6800', ['household_head' => 'SANTOS ANA', 'interview_status' => '4']);

        Survey::db()->table('f11')->insert($covered + ['member_no' => '01', 'surname' => 'BONCAROS', 'givenname' => 'JOYLYN', 'foodrecall_day' => '1']);
        $this->seedFoodLine($covered, '01', '1', 1);
        $this->seedFoodLine($covered, '01', '1', 2, ['fooditem' => 'EGG, BOILED', 'mealcode' => '3']);
        $this->seedFoodLine($covered, '01', '2', 1);
    }

    public function test_validators_do_not_get_f72_by_default(): void
    {
        $validator = User::factory()->validator()->create();

        $this->actingAs($validator)->get('/f72')->assertForbidden();
        $this->actingAs($validator)->get('/f72/export/merged')->assertForbidden();
    }

    public function test_every_view_lists_the_survey_data(): void
    {
        $this->actingAs($this->client);

        Livewire::test(F72Data::class)
            ->assertSee('Barangay 1')
            ->assertSee('50%')
            ->call('openArea', ...array_values($this->area))
            ->assertSet('tab', 'households')
            ->assertSee('SANTOS ANA')
            ->assertSee('Refused')
            ->call('showTab', 'members')
            ->assertSee('BONCAROS, JOYLYN')
            ->call('showTab', 'lines')
            ->assertSee('EGG, BOILED')
            ->set('day', '2')
            ->assertDontSee('EGG, BOILED');
    }

    public function test_the_merged_report_streams_one_row_per_food_line(): void
    {
        $response = $this->actingAs($this->client)->get('/f72/export/merged?' . http_build_query($this->area))->assertOk();
        $lines = array_values(array_filter(explode("\n", $response->streamedContent())));

        $this->assertCount(4, $lines); // header + 3 food lines
        $header = str_getcsv(substr($lines[0], 3));
        $this->assertContains('household_head', $header);
        $this->assertContains('surname', $header);
        $this->assertStringContainsString('BONCAROS', $lines[1]);
    }

    public function test_area_and_household_zips_hold_every_table(): void
    {
        $this->actingAs($this->client);

        $zipFiles = function ($response) {
            $zip = new \ZipArchive();
            $zip->open($response->baseResponse->getFile()->getPathname());
            $names = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $names[] = $zip->getNameIndex($i);
            }
            $zip->close();

            return $names;
        };

        $area = $zipFiles($this->get('/f72/export/data?' . http_build_query($this->area))->assertOk());
        $this->assertEqualsCanonicalizing(['localarea.csv', 'household_listing.csv', 'f11_data.csv', 'f72_data.csv', 'f72_merged.csv'], $area);

        $perHousehold = $zipFiles($this->get('/f72/export/households?' . http_build_query($this->area))->assertOk());
        $this->assertContains('Surigao del Norte/Dapa/Barangay 1/households/SANTOS ANA_SUN-0006-6800/f72_merged.csv', $perHousehold);

        $this->get('/f72/export/households')->assertStatus(422);
    }

    public function test_the_dashboard_shows_survey_figures(): void
    {
        $this->actingAs($this->client);

        // Overview (map first): household coverage, the SWS map, activity, what people ate
        Livewire::test(Dashboard::class)
            ->assertSee('Household coverage')
            ->assertSee('50%')
            ->assertSee('SWS households')
            ->assertSee('Latest activity')
            ->assertSee('Researchers · 24-hr recall')
            ->assertSee('What people ate · by meal')
            ->assertSee('Rice, cooked')
            ->set('province', 'NOWHERE')
            ->assertSee('No food lines yet in this area.');
    }
}
