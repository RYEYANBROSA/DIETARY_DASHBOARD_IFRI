<?php

namespace App\Support;

use App\Models\SwsActivity;
use App\Models\SwsChange;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use ZipArchive;

/**
 * Reads and writes the survey database's sws_data table for the SWS Upload
 * Data module, and keeps its audit trail - SwsActivity for the feed, SwsChange
 * per record touched - the same way F11Store does for F11. There is no delete:
 * SWS records are corrected by editing.
 *
 * transmit() sends records to the live server's sws_data (the mysql_live
 * connection), which the field researchers' tablets read. Each record carries
 * transmitted = yes / no: yes once the live server has it, back to no as soon
 * as it is changed here, so it is sent again with the next transmission.
 */
final class SwsStore
{
    public const TABLE = 'sws_data';

    /** Largest upload in data rows. */
    public const MAX_ROWS = 5000;

    /** Columns the data view can be sorted by. */
    public const SORTABLE = ['id', 'city', 'district', 'barangay', 'identifier1', 'sws_member_no', 'lastname', 'firstname', 'idp_age', 'updated_at', 'transmitted'];

    /** The live server's copy of sws_data, read by the field researchers' tablets. */
    public const LIVE_CONNECTION = 'mysql_live';

    /** Records sent to the live server per round trip. */
    private const TRANSMIT_CHUNK = 200;

    public static function live(): ConnectionInterface
    {
        return DB::connection(self::LIVE_CONNECTION);
    }

    /** Records not on the live server in their current form. */
    public static function pending(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q->where('transmitted', '!=', 'yes')->orWhereNull('transmitted'));
    }

    public static function table(): Builder
    {
        return Survey::db()->table(self::TABLE);
    }

    /** The record holding $memberNo, ignoring $exceptId. */
    public static function findByMemberNo(string $memberNo, ?int $exceptId = null): ?object
    {
        return self::table()
            ->where('sws_member_no', $memberNo)
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->first();
    }

    /** Insert a new record, or update record $id. Returns the row id. */
    public static function save(array $record, ?int $id, User $user): int
    {
        $values = self::storable($record);
        $isNew = !$id;

        if ($id) {
            $before = (array) self::table()->where('id', $id)->first();
            $changes = self::diff($before, $values);
            // A real change has to be sent to the live server again
            self::table()->where('id', $id)->update($values + ['updated_at' => now()] + ($changes ? ['transmitted' => 'no'] : []));
        } else {
            $id = (int) self::table()->insertGetId($values + ['created_at' => now(), 'updated_at' => now(), 'transmitted' => 'no']);
            $changes = null;
        }

        SwsActivity::create([
            'user_id' => $user->id,
            'action' => $isNew ? 'created' : 'updated',
            'sws_id' => $id,
            'subject' => mb_substr(self::subject($record), 0, 250),
            'area' => mb_substr(self::area($record), 0, 250),
        ]);
        // An edit that changed nothing leaves no history line
        if ($isNew || $changes) {
            SwsChange::insert([self::changeRow($user, $id, $isNew ? 'created' : 'updated', 'form', $record, $changes)]);
        }

        return $id;
    }

    /**
     * Check every row of a parsed upload: the records ready to store, the
     * problems found (per line and column), and how many are new or updates.
     */
    public static function checkUpload(array $rows): array
    {
        $records = [];
        $problems = [];
        $seen = [];

        foreach ($rows as ['line' => $line, 'data' => $data]) {
            [$record, $errors] = SwsRecord::check($data);

            foreach ($errors as $column => $message) {
                $problems[] = ['line' => $line, 'column' => strtoupper($column), 'message' => $message];
            }
            if (!$record) {
                continue;
            }

            $key = $record['sws_member_no'];
            if (isset($seen[$key])) {
                $problems[] = ['line' => $line, 'column' => 'SWS_MEMBER_NO', 'message' => "{$key} is already on line {$seen[$key]} of this file."];

                continue;
            }

            $seen[$key] = $line;
            $records[] = $record;
        }

        $existing = self::existing($records);
        $updates = count(array_filter($records, fn ($r) => isset($existing[$r['sws_member_no']])));

        return ['records' => $records, 'problems' => $problems, 'inserts' => count($records) - $updates, 'updates' => $updates];
    }

    /** Write checked upload records: new respondents inserted, existing ones updated. */
    public static function import(array $records, string $fileName, User $user): array
    {
        $inserted = 0;
        $updated = 0;
        $history = [];

        Survey::db()->transaction(function () use ($records, &$inserted, &$updated, &$history) {
            $existing = self::existing($records);

            foreach ($records as $record) {
                $values = self::storable($record);
                $before = $existing[$record['sws_member_no']] ?? null;

                if ($before) {
                    $changes = self::diff($before, $values);
                    if ($changes) {
                        self::table()->where('id', $before['id'])->update($values + ['updated_at' => now(), 'transmitted' => 'no']);
                        $history[] = [$before['id'], 'updated', $record, $changes];
                    }
                    $updated++;
                } else {
                    $id = (int) self::table()->insertGetId($values + ['created_at' => now(), 'updated_at' => now(), 'transmitted' => 'no']);
                    $inserted++;
                    $history[] = [$id, 'created', $record, null];
                }
            }
        });

        $areas = collect($records)->map(fn ($r) => self::area($r))->unique();

        $upload = SwsActivity::create([
            'user_id' => $user->id,
            'action' => 'imported',
            'subject' => mb_substr($fileName, 0, 250),
            'area' => mb_substr($areas->take(3)->implode('; ') . ($areas->count() > 3 ? ' +' . ($areas->count() - 3) . ' more' : ''), 0, 250),
            'inserted' => $inserted,
            'updated' => $updated,
        ]);

        foreach (array_chunk($history, 500) as $chunk) {
            SwsChange::insert(array_map(fn ($h) => self::changeRow($user, $h[0], $h[1], 'upload', $h[2], $h[3], $upload->id), $chunk));
        }

        return [$inserted, $updated];
    }

    // ----- Transmission to the live server -----

    /**
     * Send records $ids to the live server: inserted there, or updated when
     * the live server already has that SWS member no. A record is marked
     * transmitted only once the live server has accepted it - and only if it
     * wasn't edited here meanwhile. Returns [sent, new on live, updated on live].
     */
    public static function transmit(array $ids, User $user): array
    {
        $live = self::live();
        $columns = SwsRecord::COLUMNS;
        $sent = 0;
        $new = 0;
        $history = [];

        foreach (array_chunk($ids, self::TRANSMIT_CHUNK) as $chunk) {
            $rows = self::table()->whereIn('id', $chunk)->orderBy('id')->get(['id', 'updated_at', ...$columns]);
            if ($rows->isEmpty()) {
                continue;
            }

            $already = $live->table(self::TABLE)->whereIn('sws_member_no', $rows->pluck('sws_member_no')->all())->pluck('sws_member_no')->flip();
            $now = now();
            $values = $rows->map(fn ($r) => array_intersect_key((array) $r, array_flip($columns)) + ['created_at' => $now, 'updated_at' => $now])->all();

            $live->transaction(fn () => $live->table(self::TABLE)->upsert(
                $values,
                ['sws_member_no'],
                [...array_values(array_diff($columns, ['sws_member_no'])), 'updated_at'],
            ));

            foreach ($rows as $row) {
                // Skip the mark if someone edited the record while it was being sent
                $marked = self::table()->where('id', $row->id)
                    ->when($row->updated_at === null, fn ($q) => $q->whereNull('updated_at'), fn ($q) => $q->where('updated_at', $row->updated_at))
                    ->update(['transmitted' => 'yes', 'transmitted_at' => $now]);

                if ($marked) {
                    $history[] = [$row->id, (array) $row, isset($already[$row->sws_member_no]) ? 'updated on the live server' : 'added to the live server'];
                }
            }

            $sent += $rows->count();
            $new += $rows->count() - $already->count();
        }

        if ($sent) {
            $activity = SwsActivity::create([
                'user_id' => $user->id,
                'action' => 'transmitted',
                'subject' => "{$sent} record(s) to the live server",
                'area' => mb_substr((string) config('database.connections.' . self::LIVE_CONNECTION . '.database'), 0, 250),
                'inserted' => $new,
                'updated' => $sent - $new,
            ]);

            foreach (array_chunk($history, 500) as $part) {
                SwsChange::insert(array_map(fn ($h) => [
                    ...self::changeRow($user, $h[0], 'transmitted', 'live', $h[1], ['live' => [null, $h[2]]], $activity->id),
                ], $part));
            }
        }

        return [$sent, $new, $sent - $new];
    }

    // ----- Files -----

    /**
     * Read an uploaded CSV or XLSX into rows keyed by (lower-cased) header.
     * Returns [rows, error]; a non-null error means the file can't be used.
     */
    public static function readFile(string $path, string $extension): array
    {
        $table = strtolower($extension) === 'xlsx' ? self::xlsxRows($path) : self::csvRows($path);

        if (is_string($table)) {
            return [[], $table];
        }
        if ($table === []) {
            return [[], 'The file is empty.'];
        }

        $header = array_map(fn ($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), array_shift($table));
        $missing = array_diff(SwsRecord::COLUMNS, $header);
        if ($missing !== []) {
            return [[], 'The header row is missing: ' . strtoupper(implode(', ', $missing)) . '. Download the template and keep its first row as it is.'];
        }

        $rows = [];
        foreach ($table as $index => $cells) {
            if (count(array_filter($cells, fn ($c) => trim((string) $c) !== '')) === 0) {
                continue; // blank line
            }
            if (count($rows) >= self::MAX_ROWS) {
                return [[], 'The file has more than ' . number_format(self::MAX_ROWS) . ' rows. Split it into smaller files.'];
            }

            $data = [];
            foreach ($header as $i => $column) {
                $data[$column] = $cells[$i] ?? '';
            }
            $rows[] = ['line' => $index + 2, 'data' => $data];
        }

        return $rows === [] ? [[], 'The file has a header row but no records under it.'] : [$rows, null];
    }

    /** The template: the SWS headers, upper case as in the SWS file, with a BOM for Excel. */
    public static function templateCsv(): string
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, array_map('strtoupper', SwsRecord::COLUMNS), ',', '"', '');
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }

    /** Stream a query's records as CSV with the template's headers. */
    public static function streamCsv(Builder $query): void
    {
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        // The template's columns (so the export can be uploaded again), then the transmission status
        $columns = [...SwsRecord::COLUMNS, 'transmitted', 'transmitted_at'];
        fputcsv($out, array_map('strtoupper', $columns), ',', '"', '');

        $query->select(['id', ...$columns])->chunkById(1000, function ($rows) use ($out, $columns) {
            foreach ($rows as $row) {
                $row = (array) $row;
                fputcsv($out, array_map(fn ($c) => $row[$c] ?? '', $columns), ',', '"', '');
            }
            flush();
        });

        fclose($out);
    }

    /** @return array<int, array<int, string>>|string rows of cells, or an error */
    private static function csvRows(string $path): array|string
    {
        $handle = @fopen($path, 'r');
        if (!$handle) {
            return 'The file could not be opened.';
        }

        $first = (string) fgets($handle);
        $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        rewind($handle);

        $rows = [];
        while (($cells = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
            $rows[] = array_map(fn ($c) => (string) $c, $cells);
            if (count($rows) > self::MAX_ROWS + 1) {
                break; // readFile() reports the size
            }
        }
        fclose($handle);

        return $rows;
    }

    /**
     * The first worksheet of an .xlsx as rows of cells - enough of the format
     * for the SWS template (shared and inline strings, numbers), no library needed.
     *
     * @return array<int, array<int, string>>|string
     */
    private static function xlsxRows(string $path): array|string
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return 'This .xlsx file could not be opened. Save it again from Excel, or save it as CSV.';
        }

        $shared = [];
        if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            foreach (simplexml_load_string($xml)->si as $si) {
                $shared[] = isset($si->t) ? (string) $si->t : implode('', array_map(fn ($r) => (string) $r->t, iterator_to_array($si->r, false)));
            }
        }

        // The first sheet listed in the workbook, wherever its file is
        $sheetPath = 'xl/worksheets/sheet1.xml';
        $workbook = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbook !== false && $rels !== false) {
            $wb = simplexml_load_string($workbook);
            $first = $wb->sheets->sheet[0] ?? null;
            $rid = $first ? (string) $first->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')->id : '';
            foreach (simplexml_load_string($rels)->Relationship as $rel) {
                if ((string) $rel['Id'] === $rid) {
                    $target = ltrim((string) $rel['Target'], '/');
                    $sheetPath = str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
                }
            }
        }

        $sheet = $zip->getFromName($sheetPath);
        $zip->close();
        if ($sheet === false) {
            return 'No worksheet was found in this .xlsx file.';
        }

        $rows = [];
        foreach (simplexml_load_string($sheet)->sheetData->row ?? [] as $row) {
            $cells = [];
            foreach ($row->c as $c) {
                $index = self::columnIndex(preg_replace('/\d+/', '', (string) $c['r']));
                $type = (string) $c['t'];
                $value = match ($type) {
                    's' => $shared[(int) $c->v] ?? '',
                    'inlineStr' => (string) ($c->is->t ?? ''),
                    'b' => (string) $c->v === '1' ? 'TRUE' : 'FALSE',
                    default => (string) $c->v,
                };
                // Whole numbers Excel wrote in exponent form (long phone numbers)
                if ($type === '' && preg_match('/^-?\d+(\.\d+)?E\+?\d+$/i', $value) && floor((float) $value) == (float) $value) {
                    $value = number_format((float) $value, 0, '', '');
                }
                $cells[$index] = $value;
            }
            if ($cells !== []) {
                $filled = array_fill(0, max(array_keys($cells)) + 1, '');
                $rows[] = array_replace($filled, $cells);
            }
            if (count($rows) > self::MAX_ROWS + 1) {
                break;
            }
        }

        return $rows;
    }

    /** "A" => 0, "Z" => 25, "AA" => 26 ... */
    private static function columnIndex(string $letters): int
    {
        $index = 0;
        foreach (str_split(strtoupper($letters)) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return max(0, $index - 1);
    }

    // ----- Internals -----

    /** sws_member_no => existing row (array), for the records being checked. */
    private static function existing(array $records): array
    {
        $existing = [];
        foreach (array_chunk(array_column($records, 'sws_member_no'), 500) as $chunk) {
            foreach (self::table()->whereIn('sws_member_no', $chunk)->get() as $row) {
                $existing[$row->sws_member_no] = (array) $row;
            }
        }

        return $existing;
    }

    private static function storable(array $record): array
    {
        return array_intersect_key($record, array_flip(SwsRecord::COLUMNS));
    }

    /** {column: [old, new]} for the columns that differ. */
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

    private static function subject(array $record): string
    {
        return SwsRecord::name($record) . ' · ' . ($record['sws_member_no'] ?? '');
    }

    private static function area(array $record): string
    {
        return trim(($record['barangay'] ?? '') . ', ' . ($record['city'] ?? ''), ', ');
    }

    private static function changeRow(User $user, int $id, string $action, string $source, array $record, ?array $changes, ?int $uploadId = null): array
    {
        return [
            'sws_id' => $id,
            'user_id' => $user->id,
            'action' => $action,
            'source' => $source,
            'sws_activity_id' => $uploadId,
            'changes' => $changes === null ? null : json_encode($changes),
            'member' => mb_substr(self::subject($record), 0, 250),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
