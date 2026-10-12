<?php

namespace Tests\Unit;

use App\Support\F11Record;
use Tests\TestCase;

class F11RecordTest extends TestCase
{
    private function input(array $overrides = []): array
    {
        return [
            'province' => 'Surigao del Norte', 'municipality' => 'Dapa', 'barangay' => 'Barangay 1',
            'identifier1' => 'SUN-0005', 'identifier2' => '6722', 'identifier3' => '',
            'sws_member_no' => '6722-2', 'member_category' => 'WRA (15-49 years old)', 'member_no' => '1',
            'surname' => 'Boncaros', 'givenname' => 'Joylyn', 'birthdate' => '1990-08-27', 'sex' => 'f',
            'csc' => '', 'psc' => 'non-pregnant/non-lactating', 'foodrecall_day' => '1', 'reference_date' => '05-01-2026',
            'interview_status' => '1', 'interview_status_others' => '', 'refuse_remarks' => '',
            ...$overrides,
        ];
    }

    public function test_a_record_is_stored_in_the_tablet_formats(): void
    {
        [$record, $errors] = F11Record::check($this->input());

        $this->assertSame([], $errors);
        $this->assertSame('01', $record['member_no']);
        $this->assertSame('BONCAROS', $record['surname']);
        $this->assertSame('FEMALE', $record['sex']);
        $this->assertSame('8/27/1990', $record['birthdate']);
        $this->assertSame('05-01-2026', $record['reference_date']);
        $this->assertSame('Non-Pregnant/Non-Lactating', $record['psc']);
        $this->assertSame(35, $record['age_years']);
        $this->assertSame(0, $record['age_months']);
    }

    public function test_a_child_under_five_is_aged_in_months_up_to_the_reference_date(): void
    {
        [$record] = F11Record::check($this->input(['birthdate' => '8/24/2024', 'member_category' => 'CU5 (0-23 months old)', 'psc' => '']));

        $this->assertSame(20, $record['age_months']);
        $this->assertSame(0, $record['age_years']);
    }

    public function test_bad_values_are_reported_per_column(): void
    {
        [$record, $errors] = F11Record::check($this->input([
            'member_no' => 'A1', 'sex' => 'X', 'birthdate' => '31/31/1990', 'interview_status' => '11', 'member_category' => 'Adult',
        ]));

        $this->assertNull($record);
        $this->assertEqualsCanonicalizing(['member_no', 'sex', 'birthdate', 'interview_status', 'member_category'], array_keys($errors));
    }

    public function test_the_area_must_be_a_known_local_area_when_a_list_is_given(): void
    {
        [, $errors] = F11Record::check($this->input(), ['other|place|here' => true]);
        $this->assertArrayHasKey('barangay', $errors);

        [, $errors] = F11Record::check($this->input(), ['surigao del norte|dapa|barangay 1' => true]);
        $this->assertSame([], $errors);
    }
}
