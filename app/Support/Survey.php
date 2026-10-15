<?php

namespace App\Support;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * The survey database (the "survey" connection) and its tables.
 *
 * The tables follow the final DDCS schema - f11, f72, household_listing and
 * LocalArea - but older copies of the database carry a slightly different
 * column set (and lower-case "localarea", or extra tables such as
 * f72_addtional_question and transmission_log). Callers ask here which tables
 * and columns exist instead of assuming them.
 */
final class Survey
{
    /** Columns that place a record in a household. */
    public const HOUSEHOLD_KEYS = ['province', 'municipality', 'barangay', 'identifier1', 'identifier2', 'identifier3'];

    /** Household interview status codes (household_listing.interview_status). */
    public const HOUSEHOLD_STATUS = [
        '1' => 'Covered household',
        '2' => 'Not yet complete',
        '3' => 'Respondent incapacitated',
        '4' => 'Refused',
        '5' => 'Not at home',
        '6' => 'Away (local)',
        '7' => 'Away (abroad)',
        '8' => 'Others',
    ];

    public const WEIGHT_METHODS = [
        '1' => 'Direct weight',
        '2' => 'Photobook',
        '3' => 'FEL / model',
        '4' => 'Product weight',
        '5' => 'Others',
    ];

    private static array $tables = [];

    private static array $columns = [];

    public static function db(): ConnectionInterface
    {
        return DB::connection('survey');
    }

    /** Whether a table exists - memoised for the request. */
    public static function has(string $table): bool
    {
        return self::$tables[$table] ??= self::db()->getSchemaBuilder()->hasTable($table);
    }

    /** Column names of a table, in table order. */
    public static function columns(string $table): array
    {
        return self::$columns[$table] ??= self::db()->getSchemaBuilder()->getColumnListing($table);
    }

    /** The local area table: "LocalArea" in the final schema, "localarea" in older copies. */
    public static function localAreaTable(): ?string
    {
        foreach (['LocalArea', 'localarea'] as $name) {
            if (self::has($name)) {
                return $name;
            }
        }

        return null;
    }

    /** Forget memoised table and column lists (after creating tables, and between tests). */
    public static function flush(): void
    {
        self::$tables = [];
        self::$columns = [];
        SurveyExport::flush();
    }

    /**
     * Create any of the four final-schema tables that are missing. Existing
     * tables are left exactly as they are. Returns the names created.
     */
    public static function install(): array
    {
        $schema = self::db()->getSchemaBuilder();
        $created = [];

        $definitions = [
            'LocalArea' => function (Blueprint $t) {
                $t->increments('id');
                $t->string('province', 100)->nullable();
                $t->string('municipality', 100)->nullable();
                $t->string('barangay', 100)->nullable();
                $t->string('status', 50)->nullable();
                $t->unique(['province', 'municipality', 'barangay'], 'localarea_area_unique');
            },
            'household_listing' => function (Blueprint $t) {
                $t->increments('id');
                self::householdColumns($t);
                $t->string('household_head', 100)->nullable();
                $t->text('address')->nullable();
                $t->string('mobile_no', 20)->nullable();
                $t->string('latitude', 50)->nullable();
                $t->string('longitude', 50)->nullable();
                $t->string('foodrecall_day', 20)->nullable();
                $t->string('interview_status', 50)->nullable();
                $t->text('interview_status_others')->nullable();
                $t->string('last_intervention', 50)->nullable();
                $t->dateTime('created_at')->nullable()->useCurrent();
                $t->dateTime('edited_at')->nullable();
                $t->string('status', 50)->nullable();
                $t->text('refuse_remarks')->nullable();
                $t->string('household_id', 50)->nullable();
                $t->unique(self::HOUSEHOLD_KEYS, 'household_listing_household_unique');
            },
            'f11' => function (Blueprint $t) {
                $t->increments('id');
                self::householdColumns($t);
                $t->string('sws_member_no', 50)->nullable();
                $t->string('member_category', 50)->nullable();
                $t->string('member_no', 50);
                $t->string('surname', 100)->nullable();
                $t->string('givenname', 100)->nullable();
                $t->string('birthdate', 10)->nullable();
                $t->integer('age_months')->nullable();
                $t->integer('age_years')->nullable();
                $t->string('sex', 10)->nullable();
                $t->string('csc', 50)->nullable();
                $t->string('psc', 50)->nullable();
                $t->string('foodrecall_day', 20)->nullable();
                $t->string('reference_date', 50)->nullable();
                $t->string('interview_status', 50)->nullable();
                $t->text('interview_status_others')->nullable();
                $t->text('refuse_remarks')->nullable();
                $t->unique([...self::HOUSEHOLD_KEYS, 'member_no'], 'f11_member_unique');
            },
            'f72' => function (Blueprint $t) {
                $t->increments('id');
                $t->integer('lane_no')->nullable();
                $t->string('mixdish_id', 50)->nullable();
                $t->string('is_mixdish_heading', 10)->nullable();
                self::householdColumns($t);
                $t->string('member_no', 50)->nullable();
                $t->string('foodrecall_day', 20)->nullable();
                $t->string('no_itake', 10)->nullable();
                $t->text('no_itake_remarks')->nullable();
                $t->string('mealcode', 50)->nullable();
                $t->text('menu')->nullable();
                $t->string('meal_time', 50)->nullable();
                $t->text('fooditem')->nullable();
                $t->text('description')->nullable();
                foreach (['fic', 'foodgroup'] as $c) {
                    $t->string($c, 50)->nullable();
                }
                $t->string('foodbrand', 100)->nullable();
                foreach (['fooditemmeasure', 'foodweight'] as $c) {
                    $t->string($c, 50)->nullable();
                }
                foreach (['rf', 'referenceday', 'interview_status'] as $c) {
                    $t->string($c, 20)->nullable();
                }
                $t->string('referencedate', 20)->nullable();
                $t->text('interview_status_others')->nullable();
                $t->string('nameMother', 100)->nullable();
                foreach (['memnoMother', 'memnoRespondent'] as $c) {
                    $t->string($c, 50)->nullable();
                }
                foreach ([
                    'currentFeedingCode', 'usualFeedingCode', 'everEBF', 'durationEverEBF', 'bottleFeeding',
                    'bottle_feeding_content_breaskmilk', 'bottle_feeding_content_water', 'bottle_feeding_milk123',
                    'bottle_feeding_other_milk', 'bottle_feeding_chocolate_drinks', 'bottle_feeding_caffeinated_drinks',
                    'bottle_feeding_water_with_sugar', 'bottle_feeding_water_herbal_drinks', 'bottle_feeding_juice',
                    'bottle_feeding_clear_broth', 'bottle_feeding_am', 'bottle_feeding_CEREAL_DRINKS',
                    'bottle_feeding_child_not_bottle_fed', 'vitaminMineralSupplement', 'numberFeeding',
                ] as $c) {
                    $t->string($c, 10)->nullable();
                }
                $t->string('researcher', 100)->nullable();
                $t->text('remarks')->nullable();
                $t->dateTime('created_at')->nullable()->useCurrent();
                $t->dateTime('edited_at')->nullable()->useCurrent();
                $t->dateTime('time_start')->nullable();
                $t->dateTime('time_end')->nullable();
                $t->string('time_interview', 50)->nullable();
                $t->text('booklet_remarks')->nullable();
                foreach (['sws_member_no', 'supply_code', 'weight_method'] as $c) {
                    $t->string($c, 50)->nullable();
                }
                $t->text('weight_method_others')->nullable();
                $t->string('source_code', 50)->nullable();
                $t->text('source_code_others')->nullable();
                foreach ([
                    'recipe_id', 'cmc', 'rf_others', 'recipe_no', 'measurement_method', 'food_model', 'grams_ml',
                    'amount_proportion', 'amount_length_cm', 'inedible_waste', 'rcc',
                ] as $c) {
                    $t->string($c, 100)->nullable();
                }
                $t->unique([...self::HOUSEHOLD_KEYS, 'member_no', 'foodrecall_day', 'lane_no'], 'f72_line_unique');
                $t->index('recipe_id', 'idx_f72_recipe_key');
            },
            // SWS transmission data: one row per SWS respondent, columns named
            // after the SWS template's headers (see SwsRecord::COLUMNS), plus
            // whether each record has been transmitted to the live server
            'sws_data' => function (Blueprint $t) {
                self::swsDataColumns($t);
                self::swsTransmittedColumns($t);
            },
        ];

        foreach ($definitions as $table => $definition) {
            // The local area table may already exist under its older lower-case name
            $exists = $table === 'LocalArea' ? self::localAreaTable() !== null : $schema->hasTable($table);

            if (!$exists) {
                $schema->create($table, $definition);
                $created[] = $table;
            }
        }

        // Tables made before a column or key was added get it now
        if ($schema->hasTable('sws_data') && !$schema->hasColumn('sws_data', 'transmitted')) {
            $schema->table('sws_data', fn (Blueprint $t) => self::swsTransmittedColumns($t));
            $created[] = 'sws_data.transmitted';
        }
        if ($schema->hasTable('sws_data') && self::rekeySwsData(self::db()) !== []) {
            $created[] = 'sws_data key (identifiers 1-3 + sws_member_no)';
        }

        self::flush();

        return $created;
    }

    /**
     * The sws_data columns shared by this database and the live server's
     * copy (which the field researchers' tablets read).
     */
    public static function swsDataColumns(Blueprint $t): void
    {
        $t->increments('id');
        foreach (['city', 'district', 'barangay', 'identifier1', 'identifier2', 'identifier3'] as $c) {
            $t->string($c, 100)->nullable();
        }
        $t->string('sws_member_no', 50);
        foreach (['lastname', 'firstname'] as $c) {
            $t->string($c, 100)->nullable();
        }
        $t->string('suffix', 20)->nullable();
        $t->string('middlename', 100)->nullable();
        foreach (['floor_no', 'building_no', 'block'] as $c) {
            $t->string($c, 50)->nullable();
        }
        foreach (['street_name', 'subd'] as $c) {
            $t->string($c, 150)->nullable();
        }
        $t->string('contact_number', 20)->nullable();
        $t->string('email_address', 150)->nullable();
        $t->string('latitude', 30)->nullable();
        $t->string('longitude', 30)->nullable();
        $t->string('indexwomenname', 150)->nullable();
        $t->unsignedSmallInteger('idp_age')->nullable();
        $t->dateTime('created_at')->nullable();
        $t->dateTime('updated_at')->nullable();
        $t->index(['city', 'district', 'barangay'], 'sws_data_area_index');
        // Identifiers 1-3 + member no.: the same member no. can be in more than one household
        $t->unique(['identifier1', 'identifier2', 'identifier3', 'sws_member_no'], 'sws_data_key_unique');
        $t->index('sws_member_no', 'sws_data_member_index');
    }

    /**
     * Re-key a sws_data table made when SWS member no. alone was unique (here
     * or on the live server): blank identifiers become '' so the new key holds,
     * then the old unique index gives way to identifiers 1-3 + member no.
     * Returns what was done, or [] when it was already keyed this way.
     */
    public static function rekeySwsData(ConnectionInterface $db): array
    {
        $schema = $db->getSchemaBuilder();
        $indexes = collect($schema->getIndexes('sws_data'));
        $done = [];

        $hasKey = $indexes->contains(fn ($i) => $i['name'] === 'sws_data_key_unique');
        $old = $indexes->first(fn ($i) => $i['unique'] && !$i['primary'] && array_map('strtolower', $i['columns']) === ['sws_member_no']);

        if ($hasKey && !$old) {
            return [];
        }

        foreach (['identifier1', 'identifier2', 'identifier3'] as $c) {
            $blank = $db->table('sws_data')->whereNull($c)->update([$c => '']);
            if ($blank) {
                $done[] = "{$blank} blank {$c} set to ''";
            }
        }

        $schema->table('sws_data', function (Blueprint $t) use ($hasKey, $old, $indexes) {
            if (!$hasKey) {
                $t->unique(['identifier1', 'identifier2', 'identifier3', 'sws_member_no'], 'sws_data_key_unique');
            }
            if ($old) {
                $t->dropUnique($old['name']);
            }
            if (!$indexes->contains(fn ($i) => $i['name'] === 'sws_data_member_index')) {
                $t->index('sws_member_no', 'sws_data_member_index');
            }
        });

        $done[] = 'unique key is now identifier1 + identifier2 + identifier3 + sws_member_no' . ($old ? " (dropped {$old['name']})" : '');

        return $done;
    }

    /** Local only: yes / no, set by a transmission and reset to no by any later change. */
    private static function swsTransmittedColumns(Blueprint $t): void
    {
        $t->string('transmitted', 3)->default('no')->index('sws_data_transmitted_index');
        $t->dateTime('transmitted_at')->nullable();
    }

    private static function householdColumns(Blueprint $t): void
    {
        foreach (self::HOUSEHOLD_KEYS as $column) {
            $t->string($column, 100)->nullable();
        }
    }
}
