<?php

namespace App\Http\Controllers;

use App\Support\Survey;
use App\Support\SurveyExport;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use ZipArchive;

/**
 * The F72 data downloads, as plain routes rather than Livewire actions:
 * Livewire would base64 the finished file into its JSON response, which is
 * what timed the old dashboard's large exports out. Area filters come in the
 * query string (province / municipality / barangay, blank = all).
 */
class SurveyExportController extends Controller
{
    /** The merged F72 report as one CSV, streamed while it is read. */
    public function merged(Request $request): Response
    {
        @set_time_limit(0);
        $area = $this->area($request);

        return response()->streamDownload(
            fn () => SurveyExport::streamMerged(SurveyExport::merged($area)),
            'f72_merged_' . $this->scopeName($area) . now()->format('Y-m-d_H-i-s') . '.csv',
            ['Content-Type' => 'text/csv; charset=UTF-8', 'X-Accel-Buffering' => 'no'],
        );
    }

    /**
     * One ZIP of every table CSV plus the merged report - for an area, or for
     * one household in it when identifier1/2/3 are given.
     */
    public function data(Request $request): Response
    {
        @set_time_limit(0);
        $area = $this->area($request);

        $household = [];
        if ($request->filled('identifier1')) {
            $household = [
                'identifier1' => (string) $request->query('identifier1'),
                'identifier2' => (string) $request->query('identifier2', ''),
                'identifier3' => (string) $request->query('identifier3', ''),
            ];
        }

        $label = $household
            ? SurveyExport::safeName($request->query('head'), 'HOUSEHOLD') . '_' . SurveyExport::safeName(implode('-', array_filter($household)))
            : 'GENERATED_DATA';

        return $this->zip("{$label}_" . $this->scopeName($area) . now()->format('Y-m-d_H-i-s') . '.zip', function (ZipArchive $zip, string $workDir) use ($area, $household) {
            foreach (SurveyExport::tableFiles() as $file => $table) {
                $path = "{$workDir}/{$file}";
                SurveyExport::writeTableCsv($path, SurveyExport::scoped($table, $area, $household), $table);
                $zip->addFile($path, $file);
            }

            $path = $workDir . '/' . SurveyExport::MERGED_FILE;
            SurveyExport::writeMergedCsv($path, SurveyExport::merged($area, $household));
            $zip->addFile($path, SurveyExport::MERGED_FILE);
        });
    }

    /**
     * Every barangay of a province (or narrower), each as its own folder of
     * CSVs with a sub-folder per household:
     *
     *   PROVINCE/MUNICIPALITY/BARANGAY/*.csv
     *   PROVINCE/MUNICIPALITY/BARANGAY/households/<HEAD>_<id1>-<id2>-<id3>/*.csv
     *
     * A province is required - all provinces at once is too large for one request.
     */
    public function households(Request $request): Response
    {
        @set_time_limit(0);
        $area = $this->area($request);
        abort_if($area['province'] === '', 422, 'Choose a province first.');

        $areas = $this->areasWithData($area);
        abort_if($areas->isEmpty(), 404, 'No survey data for this area.');

        $files = SurveyExport::tableFiles();

        return $this->zip('GENERATED_MERGED_DATA_' . $this->scopeName($area) . now()->format('Y-m-d_H-i-s') . '.zip', function (ZipArchive $zip) use ($areas, $files) {
            foreach ($areas as $place) {
                $rows = [];
                foreach ($files as $table) {
                    $rows[$table] = SurveyExport::scoped($table, $place)->orderBy('id')->get();
                }
                $merged = SurveyExport::merged($place)->orderBy('f72.id')->get();

                $folder = SurveyExport::safeName($place['province'], 'NO_PROVINCE')
                    . '/' . SurveyExport::safeName($place['municipality'], 'NO_MUNICIPALITY')
                    . '/' . SurveyExport::safeName($place['barangay'], 'NO_BARANGAY');

                foreach ($files as $file => $table) {
                    $zip->addFromString("{$folder}/{$file}", SurveyExport::tableCsv($table, $rows[$table]));
                }
                $zip->addFromString("{$folder}/" . SurveyExport::MERGED_FILE, SurveyExport::mergedCsv($merged));

                // Bucket rows by household; listed households first, then any
                // that only appear in the recall data (exported as UNLISTED)
                $byHousehold = [];
                foreach ($files as $table) {
                    if (SurveyExport::isAreaOnly($table)) {
                        continue;
                    }
                    foreach ($rows[$table] as $row) {
                        $byHousehold[$this->householdKey($row)][$table][] = $row;
                    }
                }
                $mergedByHousehold = [];
                foreach ($merged as $row) {
                    $mergedByHousehold[$this->householdKey($row, SurveyExport::PREFIX)][] = $row;
                }

                $households = [];
                foreach ($rows['household_listing'] ?? [] as $hh) {
                    $households[$this->householdKey($hh)] = $hh;
                }
                foreach (array_keys($byHousehold) as $key) {
                    $households[$key] ??= null;
                }

                $used = [];
                foreach ($households as $key => $hh) {
                    $base = ($hh ? SurveyExport::safeName($hh->household_head, 'NO_HOUSEHOLD_HEAD') : 'UNLISTED')
                        . '_' . SurveyExport::safeName(implode('-', array_filter(explode('|', $key), fn ($p) => $p !== '')));
                    $name = $base;
                    for ($n = 2; isset($used[$name]); $n++) {
                        $name = "{$base}_{$n}";
                    }
                    $used[$name] = true;

                    foreach ($files as $file => $table) {
                        $tableRows = SurveyExport::isAreaOnly($table) ? $rows[$table] : ($byHousehold[$key][$table] ?? []);
                        $zip->addFromString("{$folder}/households/{$name}/{$file}", SurveyExport::tableCsv($table, $tableRows));
                    }
                    $zip->addFromString("{$folder}/households/{$name}/" . SurveyExport::MERGED_FILE, SurveyExport::mergedCsv($mergedByHousehold[$key] ?? []));
                }
            }
        });
    }

    private function area(Request $request): array
    {
        return [
            'province' => (string) $request->query('province', ''),
            'municipality' => (string) $request->query('municipality', ''),
            'barangay' => (string) $request->query('barangay', ''),
        ];
    }

    private function scopeName(array $area): string
    {
        $parts = array_filter(array_map(fn ($p) => SurveyExport::safeName(strtoupper($p)), $area));

        return $parts ? implode('_', $parts) . '_' : 'ALL_';
    }

    /** Areas under the filter that hold any listing, F11 or F72 rows. */
    private function areasWithData(array $area)
    {
        $db = Survey::db();
        $sources = collect(['household_listing', 'f11', 'f72', 'f72_addtional_question'])
            ->filter(fn ($t) => Survey::has($t))
            ->map(fn ($t) => SurveyExport::scoped($t, $area)->select('province', 'municipality', 'barangay'));

        $query = $sources->shift();
        $sources->each(fn ($q) => $query->union($q));

        return $db->query()->fromSub($query, 'areas')
            ->orderBy('province')->orderBy('municipality')->orderBy('barangay')
            ->get()
            ->map(fn ($row) => array_map(fn ($v) => (string) $v, (array) $row));
    }

    private function householdKey(object $row, string $prefix = ''): string
    {
        $row = (array) $row;

        return ($row[$prefix . 'identifier1'] ?? '') . '|' . ($row[$prefix . 'identifier2'] ?? '') . '|' . ($row[$prefix . 'identifier3'] ?? '');
    }

    /** Build a ZIP in a temporary folder, send it, and clean up afterwards. */
    private function zip(string $name, callable $fill): Response
    {
        $dir = storage_path('app/temp/' . Str::uuid());
        mkdir($dir, 0777, true);
        $zipPath = $dir . '.zip';

        $zip = new ZipArchive();
        abort_unless($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 500, 'Cannot create the ZIP file.');

        $fill($zip, $dir);
        $zip->close();

        // The CSV files are only read when the archive closes, so they go after
        foreach (glob($dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);

        return response()->download($zipPath, $name, ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }
}
