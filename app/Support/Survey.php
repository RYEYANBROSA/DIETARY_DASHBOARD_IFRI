<?php

namespace App\Support;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * The survey database (the "survey" connection) and its tables.
 *
 * The survey tables - localarea, household_listing, f11, f72 and
 * f72_interview_status - follow the live server the tablets transmit to
 * (mysql_live, see SurveySync, which keeps this copy in step with it). Older
 * copies of the database carry a slightly different column set (and
 * "LocalArea" in capitals, or extra tables such as transmission_log), so
 * callers ask here which tables and columns exist instead of assuming them.
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

    /** The local area table: "localarea" as on the live server, "LocalArea" in older copies. */
    public static function localAreaTable(): ?string
    {
        foreach (['localarea', 'LocalArea'] as $name) {
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

    /** f72 columns after the household and member keys, as on the live server: [column, length] (0 = text). */
    private const F72_COLUMNS = [
        ['no_itake', 10], ['no_itake_remarks', 0], ['mealcode', 10], ['menu', 255], ['meal_time', 20],
        ['fooditem', 255], ['description', 255], ['fic', 50], ['foodgroup', 50], ['foodbrand', 255],
        ['fooditemmeasure', 150], ['foodweight', 50], ['rf', 20], ['referenceday', 20], ['referencedate', 50],
        ['interview_status', 20], ['interview_status_others', 255], ['nameMother', 255], ['memnoMother', 20],
        ['memnoRespondent', 20], ['currentFeedingCode', 20], ['usualFeedingCode', 20], ['everEBF', 20],
        ['durationEverEBF', 20], ['bottleFeeding', 20], ['bottle_feeding_content_breaskmilk', 20],
        ['bottle_feeding_content_water', 20], ['bottle_feeding_milk123', 20], ['bottle_feeding_other_milk', 20],
        ['bottle_feeding_chocolate_drinks', 20], ['bottle_feeding_caffeinated_drinks', 20],
        ['bottle_feeding_water_with_sugar', 20], ['bottle_feeding_water_herbal_drinks', 20],
        ['bottle_feeding_juice', 20], ['bottle_feeding_clear_broth', 20], ['bottle_feeding_am', 20],
        ['bottle_feeding_CEREAL_DRINKS', 20], ['bottle_feeding_child_not_bottle_fed', 20],
        ['vitaminMineralSupplement', 20], ['numberFeeding', 20], ['researcher', 100], ['remarks', 0],
        ['time_start', 50], ['time_end', 50], ['time_interview', 50], ['booklet_remarks', 0],
        ['sws_member_no', 50], ['supply_code', 20], ['source_code', 20], ['source_code_others', 255],
        ['recipe_id', 255], ['cmc', 20], ['rf_others', 255], ['measurement_method', 20], ['food_model', 20],
        ['grams_ml', 50], ['amount_proportion', 150], ['amount_length_cm', 50], ['inedible_waste', 20],
        ['rcc', 20], ['form_eaten', 255], ['mixdish_consumed_all', 10], ['recipe_no', 20],
    ];

    /**
     * The survey tables as they are on the live server (see the CREATE TABLE
     * files the tablets' endpoints write to): table => blueprint. Text columns
     * default to '' like the live ones, but also take NULL so older data and
     * the app's own writes fit.
     */
    public static function definitions(): array
    {
        $text = fn (Blueprint $t, string $column, int $length) => $length
            ? $t->string($column, $length)->nullable()->default('')
            : $t->text($column)->nullable();
        $area = function (Blueprint $t) use ($text) {
            foreach (['province', 'municipality', 'barangay'] as $c) {
                $text($t, $c, 50);
            }
        };
        $household = function (Blueprint $t) use ($text, $area) {
            $area($t);
            foreach (['identifier1', 'identifier2', 'identifier3'] as $c) {
                $text($t, $c, 20);
            }
        };
        $stamps = function (Blueprint $t) {
            $t->dateTime('date_transmitted')->nullable()->useCurrent();
            $t->dateTime('date_updated')->nullable()->useCurrent();
        };

        return [
            'localarea' => function (Blueprint $t) use ($text, $area, $stamps) {
                $t->increments('id');
                $area($t);
                $text($t, 'status', 20);
                $stamps($t);
                $t->unique(['province', 'municipality', 'barangay'], 'uq_localarea');
                $t->index('date_updated', 'idx_localarea_updated');
            },
            'household_listing' => function (Blueprint $t) use ($text, $household, $stamps) {
                $t->increments('id');
                $household($t);
                foreach ([['household_head', 150], ['address', 255], ['mobile_no', 30], ['foodrecall_day', 10],
                    ['interview_status', 10], ['interview_status_others', 255], ['refuse_remarks', 255],
                    ['status', 20], ['edited_at', 30]] as [$c, $length]) {
                    $text($t, $c, $length);
                }
                $stamps($t);
                $t->unique(self::HOUSEHOLD_KEYS, 'uq_household_listing');
                $t->index(['province', 'municipality', 'barangay'], 'idx_hhl_area');
                $t->index('interview_status', 'idx_hhl_status');
                $t->index('date_updated', 'idx_hhl_updated');
            },
            'f11' => function (Blueprint $t) use ($text, $household, $stamps) {
                $t->increments('id');
                $household($t);
                foreach ([['member_no', 20], ['sws_member_no', 20], ['member_category', 100], ['surname', 100],
                    ['givenname', 100], ['birthdate', 30], ['age_months', 10], ['age_years', 10], ['sex', 20],
                    ['csc', 20], ['psc', 20], ['foodrecall_day', 10], ['reference_date', 30], ['interview_status', 10],
                    ['interview_status_others', 255], ['refuse_remarks', 255]] as [$c, $length]) {
                    $text($t, $c, $length);
                }
                $stamps($t);
                $t->unique([...self::HOUSEHOLD_KEYS, 'member_no'], 'uq_f11_member');
                $t->index(['province', 'municipality', 'barangay'], 'idx_f11_area');
                $t->index(['identifier1', 'identifier2', 'identifier3'], 'idx_f11_household');
                $t->index('date_updated', 'idx_f11_updated');
            },
            'f72' => function (Blueprint $t) use ($text, $household, $stamps) {
                $t->increments('id');
                $t->integer('lane_no')->default(0);
                $text($t, 'mixdish_id', 20);
                $text($t, 'is_mixdish_heading', 10);
                $household($t);
                $text($t, 'member_no', 20);
                $text($t, 'foodrecall_day', 5);
                foreach (self::F72_COLUMNS as [$c, $length]) {
                    $text($t, $c, $length);
                }
                $stamps($t);
                $t->unique([...self::HOUSEHOLD_KEYS, 'member_no', 'foodrecall_day', 'lane_no'], 'uq_f72');
                $t->index(['province', 'municipality', 'barangay'], 'idx_f72_area');
                $t->index(self::HOUSEHOLD_KEYS, 'idx_f72_household');
                $t->index('recipe_id', 'idx_f72_recipe');
                $t->index('fic', 'idx_f72_fic');
            },
            // The tablets' interview status per member and recall day - the 24-hr recall monitoring reads it
            'f72_interview_status' => function (Blueprint $t) use ($text, $household, $stamps) {
                $t->increments('id');
                $household($t);
                foreach ([['sws_member_no', 20], ['member_no', 20], ['givenname', 100], ['surname', 100],
                    ['mobile_no', 30], ['foodrecall_day', 5], ['interview_status', 10],
                    ['interview_status_others', 255], ['researcher', 100]] as [$c, $length]) {
                    $text($t, $c, $length);
                }
                $stamps($t);
                $t->unique([...self::HOUSEHOLD_KEYS, 'member_no', 'foodrecall_day'], 'uq_f72_interview_status');
                $t->index(['province', 'municipality', 'barangay'], 'idx_f72_status_area');
                $t->index('researcher', 'idx_f72_status_researcher');
                $t->index('date_updated', 'idx_f72_status_updated');
            },
        ];
    }

    /**
     * Create any of the survey tables that are missing. Existing tables are
     * left as they are (see rebuild() to bring one in line with the live
     * layout). Returns the names created.
     */
    public static function install(): array
    {
        $schema = self::db()->getSchemaBuilder();
        $created = [];

        $definitions = self::definitions() + [
            // SWS transmission data: one row per SWS respondent, columns named
            // after the SWS template's headers (see SwsRecord::COLUMNS), plus
            // whether each record has been transmitted to the live server
            'sws_data' => function (Blueprint $t) {
                self::swsDataColumns($t);
                self::swsTransmittedColumns($t);
            },
        ];

        foreach ($definitions as $table => $definition) {
            // The local area table may already exist under its older name, "LocalArea"
            $exists = $table === 'localarea' ? self::localAreaTable() !== null : $schema->hasTable($table);

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
     * Whether a survey table's columns differ from the live layout
     * (definitions()) - extra, missing or renamed columns.
     */
    public static function differs(string $table): bool
    {
        $name = $table === 'localarea' ? self::localAreaTable() : $table;
        if ($name === null || !self::has($name)) {
            return false;
        }

        return array_map('strtolower', self::columns($name)) !== array_map('strtolower', self::layoutColumns($table));
    }

    /**
     * Bring an existing survey table to the live layout, keeping its rows: a
     * new table is made from definitions(), the columns both share are copied
     * across (by id), and it takes the old one's place. Rows that would break
     * the new unique key are skipped. Returns [rows copied, rows before].
     */
    public static function rebuild(string $table): array
    {
        $db = self::db();
        $schema = $db->getSchemaBuilder();
        $current = $table === 'localarea' ? self::localAreaTable() : $table;
        $old = $table . '_before_rebuild';

        // Move the old table aside and free its index names (SQLite keeps them database-wide)
        $schema->dropIfExists($old);
        $schema->rename($current, $old);
        foreach ($schema->getIndexes($old) as $index) {
            if (!$index['primary']) {
                $schema->table($old, fn (Blueprint $t) => $index['unique'] ? $t->dropUnique($index['name']) : $t->dropIndex($index['name']));
            }
        }
        self::flush();

        $schema->create($table, self::definitions()[$table]);

        $shared = array_values(array_intersect(self::columns($old), $schema->getColumnListing($table)));
        $before = $db->table($old)->count();
        $copied = 0;
        $db->table($old)->orderBy('id')->chunk(500, function ($rows) use ($db, $table, $shared, &$copied) {
            $copied += $db->table($table)->insertOrIgnore($rows->map(fn ($r) => array_intersect_key((array) $r, array_flip($shared)))->all());
        });

        $schema->drop($old);
        self::flush();

        return [$copied, $before];
    }

    /** The column names definitions() gives a table, in order. */
    public static function layoutColumns(string $table): array
    {
        $blueprint = new Blueprint(self::db(), $table);
        self::definitions()[$table]($blueprint);

        return array_map(fn ($c) => $c->name, $blueprint->getColumns());
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

    /** The f72 column holding how a food was weighed: measurement_method on the live layout, weight_method in older copies. */
    public static function weightColumn(): ?string
    {
        foreach (['measurement_method', 'weight_method'] as $column) {
            if (self::has('f72') && in_array($column, self::columns('f72'), true)) {
                return $column;
            }
        }

        return null;
    }
}
