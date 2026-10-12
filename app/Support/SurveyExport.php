<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;

/**
 * CSV exports of the survey data, as the old DDCS dashboard produced them.
 * Every export (one household, an area, or everything) ships the survey tables
 * as they stand plus the merged F72 report:
 *
 *   localarea.csv  household_listing.csv  f11_data.csv  f72_data.csv
 *   f72_additional_questions.csv (when that table exists)  f72_merged.csv
 *
 * The merged report has one row per f72 food line with the household, member
 * and additional-question records laid out beside it:
 *
 *   household_listing.* | f11.* | f72.* | f72_addtional_question.*
 *
 * Every join is LEFT from f72, so a food line whose household was never listed
 * or whose member has no f11 record still appears, with those blocks blank.
 */
final class SurveyExport
{
    public const MERGED_FILE = 'f72_merged.csv';

    /** Alias prefix of the main table, for reading f72 values off a merged row. */
    public const PREFIX = 'f72__';

    private static ?array $map = null;

    /** CSV file name => table, for the tables this database has. */
    public static function tableFiles(): array
    {
        $files = [
            'localarea.csv' => Survey::localAreaTable(),
            'household_listing.csv' => 'household_listing',
            'f11_data.csv' => 'f11',
            'f72_data.csv' => 'f72',
            'f72_additional_questions.csv' => 'f72_addtional_question',
        ];

        return array_filter($files, fn ($table) => $table !== null && Survey::has($table));
    }

    /** Tables without household identifiers - scoped to the area only. */
    public static function isAreaOnly(string $table): bool
    {
        return strtolower($table) === 'localarea';
    }

    /** A survey table filtered to an area (blank parts mean "all") and optionally one household. */
    public static function scoped(string $table, array $area, array $household = [], string $prefix = ''): Builder
    {
        $query = $table === 'f72' && $prefix !== '' ? self::mergedQuery() : Survey::db()->table($table);

        foreach (['province', 'municipality', 'barangay'] as $column) {
            if (($area[$column] ?? '') !== '') {
                $query->where($prefix . $column, $area[$column]);
            }
        }

        if ($household !== [] && !self::isAreaOnly($table)) {
            foreach (['identifier1', 'identifier2', 'identifier3'] as $column) {
                $query->where($prefix . $column, $household[$column] ?? '');
            }
        }

        return $query;
    }

    /** The merged report, scoped like scoped(). */
    public static function merged(array $area, array $household = []): Builder
    {
        return self::scoped('f72', $area, $household, 'f72.');
    }

    // ----- Writing -----

    /** Stream a table query into a CSV file. Returns the rows written, or false if unwritable. */
    public static function writeTableCsv(string $path, Builder $query, string $table): int|false
    {
        $handle = @fopen($path, 'w');
        if ($handle === false) {
            return false;
        }

        $columns = Survey::columns($table);
        self::start($handle, $columns);

        $written = 0;
        $query->orderBy('id')->chunk(1000, function ($rows) use ($handle, $columns, &$written) {
            foreach ($rows as $row) {
                self::line($handle, (array) $row, $columns);
                $written++;
            }
        });

        fclose($handle);

        return $written;
    }

    /** Stream a merged query into a CSV file. Returns the rows written, or false if unwritable. */
    public static function writeMergedCsv(string $path, Builder $query): int|false
    {
        $handle = @fopen($path, 'w');
        if ($handle === false) {
            return false;
        }

        $written = self::writeMerged($handle, $query, false);
        fclose($handle);

        return $written;
    }

    /** Echo the merged report straight into the response, flushing per chunk. */
    public static function streamMerged(Builder $query): void
    {
        $handle = fopen('php://output', 'w');
        self::writeMerged($handle, $query, true);
        fclose($handle);
    }

    /** Already-fetched table rows as one CSV string. */
    public static function tableCsv(string $table, iterable $rows): string
    {
        $columns = Survey::columns($table);

        return self::toString(function ($handle) use ($rows, $columns) {
            self::start($handle, $columns);
            foreach ($rows as $row) {
                self::line($handle, (array) $row, $columns);
            }
        });
    }

    /** Already-fetched merged rows as one CSV string. */
    public static function mergedCsv(iterable $rows): string
    {
        $aliases = array_keys(self::map());

        return self::toString(function ($handle) use ($rows, $aliases) {
            self::start($handle, self::headers());
            foreach ($rows as $row) {
                self::line($handle, (array) $row, $aliases);
            }
        });
    }

    /** Strip anything that would break a file name or archive path segment. */
    public static function safeName(?string $value, string $fallback = ''): string
    {
        $value = preg_replace('/[^A-Za-z0-9 ._-]/', '', (string) $value);
        $value = trim(preg_replace('/\s+/', ' ', $value));

        return substr($value, 0, 80) ?: $fallback;
    }

    // ----- Merged report internals -----

    /** f72 left joined to its household listing, member and additional-question records. */
    private static function mergedQuery(): Builder
    {
        $select = [];
        foreach (self::map() as $alias => [$table, $column]) {
            $select[] = "{$table}.{$column} as {$alias}";
        }

        // Null-safe equality, since identifier3 is often missing
        $same = Survey::db()->getDriverName() === 'sqlite' ? 'is' : '<=>';

        $query = Survey::db()->table('f72');

        if (Survey::has('household_listing')) {
            $query->leftJoin('household_listing', fn ($join) => self::onHousehold($join, 'household_listing', $same));
        }
        if (Survey::has('f11')) {
            // f11 holds one row per member whichever day they were interviewed,
            // so the member number alone narrows the household to the person
            $query->leftJoin('f11', fn ($join) => self::onHousehold($join, 'f11', $same)->on('f11.member_no', $same, 'f72.member_no'));
        }
        if (Survey::has('f72_addtional_question')) {
            // One row per member per recall day - the day is part of the key
            $query->leftJoin('f72_addtional_question', fn ($join) => self::onHousehold($join, 'f72_addtional_question', $same)
                ->on('f72_addtional_question.member_no', $same, 'f72.member_no')
                ->on('f72_addtional_question.foodrecall_day', $same, 'f72.foodrecall_day'));
        }

        return $query->select($select);
    }

    private static function onHousehold($join, string $table, string $same)
    {
        foreach (Survey::HOUSEHOLD_KEYS as $column) {
            $join->on("{$table}.{$column}", $same, "f72.{$column}");
        }

        return $join;
    }

    /** alias => [table, column], in output order. */
    private static function map(): array
    {
        if (self::$map === null) {
            self::$map = [];
            $blocks = ['hl__' => 'household_listing', 'f11__' => 'f11', self::PREFIX => 'f72', 'fq__' => 'f72_addtional_question'];

            foreach ($blocks as $prefix => $table) {
                if (!Survey::has($table)) {
                    continue;
                }
                foreach (Survey::columns($table) as $column) {
                    self::$map[$prefix . $column] = [$table, $column];
                }
            }
        }

        return self::$map;
    }

    /** Header row: the raw survey column names, duplicates across blocks kept. */
    private static function headers(): array
    {
        return array_column(array_values(self::map()), 1);
    }

    private static function writeMerged($handle, Builder $query, bool $flush): int
    {
        self::start($handle, self::headers());
        $aliases = array_keys(self::map());
        $written = 0;

        // Keyset-paginated on f72.id: with three joins, deep offsets get slow
        $query->chunkById(1000, function ($rows) use ($handle, $aliases, $flush, &$written) {
            foreach ($rows as $row) {
                self::line($handle, (array) $row, $aliases);
                $written++;
            }
            if ($flush) {
                flush();
            }
        }, 'f72.id', self::PREFIX . 'id');

        return $written;
    }

    private static function start($handle, array $header): void
    {
        // BOM so Excel renders accented place names and food items correctly
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, $header, ',', '"', '');
    }

    private static function line($handle, array $record, array $keys): void
    {
        $line = [];
        foreach ($keys as $key) {
            $line[] = $record[$key] ?? '';
        }
        fputcsv($handle, $line, ',', '"', '');
    }

    private static function toString(callable $write): string
    {
        $stream = fopen('php://temp', 'r+');
        $write($stream);
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }

    /** Forget the resolved column map (tests switch schemas between cases). */
    public static function flush(): void
    {
        self::$map = null;
    }
}
