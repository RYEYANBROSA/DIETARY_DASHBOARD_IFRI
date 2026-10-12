<?php

namespace Tests\Concerns;

use App\Support\Survey;

/**
 * Gives a test a fresh survey database (in-memory SQLite, see phpunit.xml)
 * with the final-schema tables, plus helpers to fill it.
 */
trait UsesSurveyDatabase
{
    protected function setUpSurveyDatabase(): void
    {
        Survey::flush();
        Survey::install();
    }

    protected function seedArea(string $province = 'Surigao del Norte', string $municipality = 'Dapa', string $barangay = 'Barangay 1'): array
    {
        Survey::db()->table('LocalArea')->insert(compact('province', 'municipality', 'barangay') + ['status' => 'transmitted']);

        return compact('province', 'municipality', 'barangay');
    }

    protected function seedHousehold(array $area, string $id1, string $id2, array $extra = []): array
    {
        $row = $area + ['identifier1' => $id1, 'identifier2' => $id2, 'identifier3' => ''];
        Survey::db()->table('household_listing')->insert([...$row, 'household_head' => 'DELA CRUZ JUAN', 'interview_status' => '1', ...$extra]);

        return $row;
    }

    protected function seedFoodLine(array $household, string $memberNo, string $day, int $lane, array $extra = []): void
    {
        Survey::db()->table('f72')->insert([
            ...$household,
            'member_no' => $memberNo,
            'foodrecall_day' => $day,
            'lane_no' => $lane,
            'mealcode' => '1',
            'fooditem' => 'RICE, COOKED',
            'foodweight' => '150',
            'weight_method' => '1',
            'researcher' => 'enum01',
            ...$extra,
        ]);
    }
}
