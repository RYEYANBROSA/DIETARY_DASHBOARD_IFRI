<?php

namespace App\Support;

use App\Models\F11Activity;
use App\Models\F11Change;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Reads and writes the survey database's f11 table for the F11 module, and
 * keeps the audit trail in step with every write: one F11Activity line per
 * action for the activity feed, and one F11Change per member touched - who,
 * how (form or upload) and what changed - for validation.
 */
final class F11Store
{
    /** Largest CSV the upload accepts, in data rows. */
    public const MAX_ROWS = 5000;

    /**
     * Largest upload the server takes, in KB: 10 MB, or less when PHP's
     * upload_max_filesize / post_max_size are lower (8 MB on a stock WAMP).
     * Past that limit PHP drops the file before Livewire sees it.
     */
    public static function maxUploadKb(): int
    {
        $toKb = function (string $value): int {
            $value = trim($value);
            $number = (float) $value;

            return (int) match (strtolower(substr($value, -1))) {
                'g' => $number * 1024 * 1024,
                'm' => $number * 1024,
                'k' => $number,
                default => $number / 1024,
            };
        };

        $limits = array_filter([10240, $toKb((string) ini_get('upload_max_filesize')), $toKb((string) ini_get('post_max_size')) - 64], fn ($kb) => $kb > 0);

        return (int) min($limits);
    }

    /**
     * Every province / municipality / barangay an F11 record can be placed in:
     * the local area list plus any area that has listed households.
     */
    public static function areaOptions(): Collection
    {
        $db = Survey::db();
        $sources = [];

        if ($table = Survey::localAreaTable()) {
            $sources[] = $db->table($table)->select('province', 'municipality', 'barangay');
        }
        if (Survey::has('household_listing')) {
            $sources[] = $db->table('household_listing')->select('province', 'municipality', 'barangay');
        }
        if ($sources === []) {
            return collect();
        }

        $query = array_shift($sources);
        foreach ($sources as $source) {
            $query->union($source);
        }

        return $db->query()->fromSub($query, 'areas')
            ->whereNotNull('province')->where('province', '!=', '')
            ->orderBy('province')->orderBy('municipality')->orderBy('barangay')
            ->get()
            ->map(fn ($row) => (array) $row);
    }

    /** Area keys an upload is checked against, or null when there is no area list to check. */
    public static function knownAreas(): ?array
    {
        $areas = self::areaOptions();

        return $areas->isEmpty() ? null : F11Record::areaKeys($areas);
    }

    /** Listed households of one barangay, for the identifier picker. */
    public static function households(string $province, string $municipality, string $barangay): Collection
    {
        if (!Survey::has('household_listing')) {
            return collect();
        }

        return Survey::db()->table('household_listing')
            ->where(compact('province', 'municipality', 'barangay'))
            ->orderBy('identifier1')->orderBy('identifier2')
            ->get(['identifier1', 'identifier2', 'identifier3', 'household_head']);
    }

    /** The f11 row holding $key, ignoring $exceptId. */
    public static function findByKey(array $key, ?int $exceptId = null): ?object
    {
        return Survey::db()->table('f11')
            ->where($key)
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->first();
    }

    /**
     * Insert a new member, or update member $id. Returns the row id. There is
     * deliberately no delete: F11 records are corrected, never removed.
     */
    public static function save(array $record, ?int $id, User $user): int
    {
        $values = self::storable($record);
        $db = Survey::db();
        $isNew = !$id;

        if ($id) {
            $before = (array) $db->table('f11')->where('id', $id)->first();
            $db->table('f11')->where('id', $id)->update($values);
            $changes = self::diff($before, $values);
        } else {
            $id = (int) $db->table('f11')->insertGetId($values);
            $changes = null;
        }

        self::log($user, $isNew ? 'created' : 'updated', $record, $id);
        // An edit that changed nothing leaves no history line
        if ($isNew || $changes) {
            self::change($user, $id, $isNew ? 'created' : 'updated', 'form', $record, $changes);
        }

        return $id;
    }

    /**
     * Check every row of a parsed upload. Returns the records ready to store,
     * the problems found (one entry per row and column), and how many of the
     * records are new versus already in f11.
     */
    public static function checkUpload(array $rows): array
    {
        $areas = self::knownAreas();
        $records = [];
        $problems = [];
        $seen = [];

        foreach ($rows as ['line' => $line, 'data' => $data]) {
            [$record, $errors] = F11Record::check($data, $areas);

            foreach ($errors as $column => $message) {
                $problems[] = ['line' => $line, 'column' => $column, 'message' => $message];
            }
            if (!$record) {
                continue;
            }

            $key = implode('|', F11Record::key($record));
            if (isset($seen[$key])) {
                $problems[] = ['line' => $line, 'column' => 'member_no', 'message' => "The same member is already on line {$seen[$key]} of this file."];

                continue;
            }

            $seen[$key] = $line;
            $records[] = $record;
        }

        $existing = self::existingKeys($records);
        $updates = count(array_filter($records, fn ($r) => isset($existing[implode('|', F11Record::key($r))])));

        return [
            'records' => $records,
            'problems' => $problems,
            'inserts' => count($records) - $updates,
            'updates' => $updates,
        ];
    }

    /** Write checked upload records: new members inserted, existing ones updated. */
    public static function import(array $records, string $fileName, User $user): array
    {
        $db = Survey::db();
        $inserted = 0;
        $updated = 0;
        $history = [];

        $db->transaction(function () use ($db, $records, &$inserted, &$updated, &$history) {
            $existing = self::existingKeys($records);

            foreach ($records as $record) {
                $values = self::storable($record);
                $before = $existing[implode('|', F11Record::key($record))] ?? null;

                if ($before) {
                    $db->table('f11')->where('id', $before['id'])->update($values);
                    $updated++;
                    if ($changes = self::diff($before, $values)) {
                        $history[] = [$before['id'], 'updated', $record, $changes];
                    }
                } else {
                    $id = (int) $db->table('f11')->insertGetId($values);
                    $inserted++;
                    $history[] = [$id, 'created', $record, null];
                }
            }
        });

        $areas = collect($records)->map(fn ($r) => $r['barangay'] . ', ' . $r['municipality'])->unique();

        $upload = F11Activity::create([
            'user_id' => $user->id,
            'action' => 'imported',
            'subject' => mb_substr($fileName, 0, 250),
            'area' => mb_substr($areas->take(3)->implode('; ') . ($areas->count() > 3 ? ' +' . ($areas->count() - 3) . ' more' : ''), 0, 250),
            'inserted' => $inserted,
            'updated' => $updated,
        ]);

        foreach (array_chunk($history, 500) as $chunk) {
            F11Change::insert(array_map(fn ($h) => self::changeRow($user, $h[0], $h[1], 'upload', $h[2], $h[3], $upload->id), $chunk));
        }

        return [$inserted, $updated];
    }

    /**
     * Read an uploaded CSV into rows keyed by column name. Returns [rows, error];
     * a non-null error means the file as a whole can't be used.
     */
    public static function readCsv(string $path): array
    {
        $handle = @fopen($path, 'r');
        if (!$handle) {
            return [[], 'The file could not be opened.'];
        }

        $first = (string) fgets($handle);
        $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        rewind($handle);

        $header = fgetcsv($handle, null, $delimiter, '"', '');
        if (!$header) {
            fclose($handle);

            return [[], 'The file is empty.'];
        }

        $header = array_map(fn ($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), $header);
        $missing = array_diff(F11Record::COLUMNS, $header);
        if ($missing !== []) {
            fclose($handle);

            return [[], 'The header row is missing: ' . implode(', ', $missing) . '. Download the template and keep its first row as it is.'];
        }

        $rows = [];
        $line = 1;
        while (($cells = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
            $line++;
            if (count(array_filter($cells, fn ($c) => trim((string) $c) !== '')) === 0) {
                continue; // blank line
            }
            if (count($rows) >= self::MAX_ROWS) {
                fclose($handle);

                return [[], 'The file has more than ' . number_format(self::MAX_ROWS) . ' rows. Split it into smaller files.'];
            }

            $data = [];
            foreach ($header as $index => $column) {
                $data[$column] = $cells[$index] ?? '';
            }
            $rows[] = ['line' => $line, 'data' => $data];
        }
        fclose($handle);

        if ($rows === []) {
            return [[], 'The file has a header row but no members under it.'];
        }

        return [$rows, null];
    }

    /** The template: the header row only, with a BOM so Excel opens it as UTF-8. */
    public static function templateCsv(): string
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, F11Record::COLUMNS, ',', '"', '');
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }

    /** key string => existing f11 row (array), for the records' barangays. */
    private static function existingKeys(array $records): array
    {
        $areas = collect($records)
            ->map(fn ($r) => ['province' => $r['province'], 'municipality' => $r['municipality'], 'barangay' => $r['barangay']])
            ->unique(fn ($a) => implode('|', $a));

        $keys = [];
        foreach ($areas as $area) {
            Survey::db()->table('f11')->where($area)->orderBy('id')
                ->each(function ($row) use (&$keys) {
                    $row = (array) $row;
                    $values = array_map(fn ($c) => (string) ($row[$c] ?? ''), F11Record::KEY);
                    $keys[implode('|', $values)] = $row;
                });
        }

        return $keys;
    }

    /** Only the columns this copy of f11 actually has. */
    private static function storable(array $record): array
    {
        return array_intersect_key($record, array_flip(Survey::columns('f11')));
    }

    /** {column: [old, new]} for the columns $values sets that differ from $before. */
    private static function diff(array $before, array $values): array
    {
        $changes = [];
        foreach ($values as $column => $new) {
            $old = $before[$column] ?? null;
            if ((string) $old !== (string) $new) {
                $changes[$column] = [$old, $new];
            }
        }

        return $changes;
    }

    private static function change(User $user, int $id, string $action, string $source, array $record, ?array $changes): void
    {
        F11Change::insert([self::changeRow($user, $id, $action, $source, $record, $changes)]);
    }

    private static function changeRow(User $user, int $id, string $action, string $source, array $record, ?array $changes, ?int $uploadId = null): array
    {
        $household = collect([$record['identifier1'] ?? '', $record['identifier2'] ?? '', $record['identifier3'] ?? ''])
            ->filter(fn ($v) => $v !== '' && $v !== null)->implode('-');

        return [
            'f11_id' => $id,
            'user_id' => $user->id,
            'action' => $action,
            'source' => $source,
            'f11_activity_id' => $uploadId,
            'changes' => $changes === null ? null : json_encode($changes),
            'member' => mb_substr(trim(($record['surname'] ?? '') . ', ' . ($record['givenname'] ?? ''), ', ') . " · {$household} #" . ($record['member_no'] ?? ''), 0, 250),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private static function log(User $user, string $action, array $record, int $id): void
    {
        $household = collect([$record['identifier1'] ?? '', $record['identifier2'] ?? '', $record['identifier3'] ?? ''])
            ->filter(fn ($v) => $v !== '' && $v !== null)->implode('-');

        F11Activity::create([
            'user_id' => $user->id,
            'action' => $action,
            'f11_id' => $id,
            'subject' => mb_substr(trim(($record['surname'] ?? '') . ', ' . ($record['givenname'] ?? ''), ', ') . " · {$household} #" . ($record['member_no'] ?? ''), 0, 250),
            'area' => mb_substr(($record['barangay'] ?? '') . ', ' . ($record['municipality'] ?? ''), 0, 250),
        ]);
    }
}
