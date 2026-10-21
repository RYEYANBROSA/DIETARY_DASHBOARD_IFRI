<?php

use App\Support\Survey;
use Illuminate\Database\Migrations\Migration;

/**
 * The survey tables (localarea, household_listing, f11, f72,
 * f72_interview_status) now follow the live server's layout - see
 * Survey::definitions(). Tables already here in an older layout are rebuilt
 * in place, keeping their rows; missing ones are created. A survey database
 * without any survey tables yet (a fresh install, the tests) is left alone -
 * `php artisan survey:install-schema` sets that up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Survey::flush();
        $tables = array_keys(Survey::definitions());
        $existing = array_filter($tables, fn ($t) => $t === 'localarea' ? Survey::localAreaTable() !== null : Survey::has($t));

        if ($existing === []) {
            return;
        }

        foreach ($existing as $table) {
            if (Survey::differs($table)) {
                Survey::rebuild($table);
            }
        }
        Survey::install();
    }

    public function down(): void
    {
        // The older layout isn't restored: the live layout is the one the survey uses now
    }
};
