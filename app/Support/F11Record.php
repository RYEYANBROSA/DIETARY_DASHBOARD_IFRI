<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * One F11 household member, as encoded on the form or read from a CSV upload.
 * Both paths go through check(), so a record saved either way is validated and
 * stored identically - in the same shapes the field tablets write:
 *
 *   names        upper case                  BONCAROS
 *   member_no    two digits                  01
 *   birthdate    month/day/year, no padding  8/27/1990
 *   reference    mm-dd-yyyy                  05-01-2026
 *   ages         children under 5 in age_months (age_years 0),
 *                everyone else in age_years (age_months 0),
 *                both counted up to the reference date
 */
final class F11Record
{
    /** The CSV template, in column order. age_months / age_years are worked out, not uploaded. */
    public const COLUMNS = [
        'province', 'municipality', 'barangay',
        'identifier1', 'identifier2', 'identifier3',
        'sws_member_no', 'member_category', 'member_no',
        'surname', 'givenname', 'birthdate', 'sex',
        'csc', 'psc', 'foodrecall_day', 'reference_date',
        'interview_status', 'interview_status_others', 'refuse_remarks',
    ];

    /** The columns that identify a member (the table's unique key). */
    public const KEY = [...Survey::HOUSEHOLD_KEYS, 'member_no'];

    public const CATEGORIES = [
        'WRA (15-49 years old)',
        'CU5 (0-23 months old)',
        'CU5 (24-59 months old)',
    ];

    public const PSC = [
        'Non-Pregnant/Non-Lactating',
        'Pregnant',
        'Lactating',
    ];

    public const STATUS = [
        '1' => 'Completed',
        '2' => 'Not yet complete',
        '3' => 'Respondent incapacitated',
        '4' => 'Refused',
        '5' => 'Not at home',
        '6' => 'Away (local)',
        '7' => 'Away (abroad)',
        '8' => 'Others',
        '9' => 'Code 9',
        '10' => 'Others (specify)',
    ];

    /** Accepted date spellings, tried in order. */
    private const DATE_FORMATS = ['Y-m-d', 'n/j/Y', 'm/d/Y', 'n-j-Y', 'm-d-Y', 'Y/m/d'];

    /** Friendly names for error messages. */
    public const LABELS = [
        'identifier1' => 'identifier 1',
        'identifier2' => 'identifier 2',
        'identifier3' => 'identifier 3',
        'sws_member_no' => 'SWS member no.',
        'member_category' => 'member category',
        'member_no' => 'member no.',
        'givenname' => 'given name',
        'csc' => 'CSC',
        'psc' => 'PSC',
        'foodrecall_day' => 'recall day',
        'reference_date' => 'reference date',
        'interview_status' => 'interview status',
        'interview_status_others' => 'interview status (others)',
        'refuse_remarks' => 'refusal remarks',
    ];

    /**
     * Validate raw input and return [record ready to store, errors keyed by
     * column]. $areas, when given, is the set of known "province|municipality|barangay"
     * keys (lower case) the area must be one of.
     */
    public static function check(array $input, ?array $areas = null): array
    {
        $data = [];
        foreach (self::COLUMNS as $column) {
            $value = $input[$column] ?? '';
            $data[$column] = is_string($value) || is_numeric($value) ? trim((string) $value) : '';
        }

        // Lenient spellings the template guide allows
        $data['sex'] = match (strtoupper($data['sex'])) {
            'M', 'MALE' => 'MALE',
            'F', 'FEMALE' => 'FEMALE',
            default => $data['sex'],
        };
        $data['member_category'] = self::matchOption($data['member_category'], self::CATEGORIES);
        $data['psc'] = self::matchOption($data['psc'], self::PSC);

        $validator = Validator::make($data, [
            'province' => ['required', 'max:100'],
            'municipality' => ['required', 'max:100'],
            'barangay' => ['required', 'max:100'],
            'identifier1' => ['required', 'max:100'],
            'identifier2' => ['required', 'max:100'],
            'identifier3' => ['nullable', 'max:100'],
            'sws_member_no' => ['nullable', 'max:50'],
            'member_category' => ['required', Rule::in(self::CATEGORIES)],
            'member_no' => ['required', 'regex:/^\d{1,3}$/'],
            'surname' => ['required', 'max:100'],
            'givenname' => ['required', 'max:100'],
            'birthdate' => ['required'],
            'sex' => ['required', Rule::in(['MALE', 'FEMALE'])],
            'csc' => ['nullable', 'max:50'],
            'psc' => ['nullable', Rule::in(self::PSC)],
            'foodrecall_day' => ['required', Rule::in(['1', '2'])],
            'reference_date' => ['required'],
            'interview_status' => ['required', Rule::in(array_keys(self::STATUS))],
            'interview_status_others' => ['nullable', 'max:500'],
            'refuse_remarks' => ['nullable', 'max:500'],
        ], [
            'member_no.regex' => 'The member no. must be a number of up to 3 digits, like 01.',
            'member_category.in' => 'The member category must be one of: ' . implode('; ', self::CATEGORIES) . '.',
            'psc.in' => 'The PSC must be blank or one of: ' . implode('; ', self::PSC) . '.',
            'sex.in' => 'The sex must be MALE or FEMALE.',
            'foodrecall_day.in' => 'The recall day must be 1 or 2.',
            'interview_status.in' => 'The interview status must be a code from 1 to 10.',
        ], self::LABELS);

        $errors = $validator->errors()->toArray();
        $errors = array_map(fn ($messages) => $messages[0], $errors);

        $birth = self::parseDate($data['birthdate']);
        $reference = self::parseDate($data['reference_date']);
        $today = CarbonImmutable::today();

        if ($data['reference_date'] !== '' && !$reference) {
            $errors['reference_date'] ??= 'The reference date is not a valid date (use YYYY-MM-DD or MM-DD-YYYY).';
        } elseif ($reference && $reference->greaterThan($today)) {
            $errors['reference_date'] ??= 'The reference date cannot be in the future.';
        }

        if ($data['birthdate'] !== '' && !$birth) {
            $errors['birthdate'] ??= 'The birthdate is not a valid date (use YYYY-MM-DD or M/D/YYYY).';
        } elseif ($birth && $birth->greaterThan($reference ?? $today)) {
            $errors['birthdate'] ??= 'The birthdate cannot be after the reference date.';
        }

        if ($areas !== null && !isset($errors['province']) && !isset($errors['municipality']) && !isset($errors['barangay'])) {
            $key = strtolower($data['province'] . '|' . $data['municipality'] . '|' . $data['barangay']);
            if ($data['barangay'] !== '' && !isset($areas[$key])) {
                $errors['barangay'] ??= 'This province / municipality / barangay is not in the local area list.';
            }
        }

        if ($errors !== []) {
            return [null, $errors];
        }

        $months = (int) $birth->diffInMonths($reference, false);
        $isChild = $months < 60;

        $record = [
            ...$data,
            'member_no' => str_pad(ltrim($data['member_no'], '0') ?: '0', 2, '0', STR_PAD_LEFT),
            'surname' => mb_strtoupper($data['surname']),
            'givenname' => mb_strtoupper($data['givenname']),
            'birthdate' => $birth->format('n/j/Y'),
            'reference_date' => $reference->format('m-d-Y'),
            'age_months' => $isChild ? max(0, $months) : 0,
            'age_years' => $isChild ? 0 : intdiv($months, 12),
        ];

        return [$record, []];
    }

    /** The unique-key values of a record. */
    public static function key(array $record): array
    {
        return array_intersect_key($record, array_flip(self::KEY));
    }

    /** A stored row as form input (dates as YYYY-MM-DD for date pickers). */
    public static function toForm(object|array $row): array
    {
        $row = (array) $row;
        $form = [];
        foreach (self::COLUMNS as $column) {
            $form[$column] = (string) ($row[$column] ?? '');
        }

        foreach (['birthdate', 'reference_date'] as $column) {
            $date = self::parseDate($form[$column]);
            $form[$column] = $date ? $date->format('Y-m-d') : $form[$column];
        }

        return $form;
    }

    /** "province|municipality|barangay" keys, lower case, from rows that carry those three. */
    public static function areaKeys(iterable $rows): array
    {
        $keys = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $keys[strtolower($row['province'] . '|' . $row['municipality'] . '|' . $row['barangay'])] = true;
        }

        return $keys;
    }

    public static function parseDate(?string $value): ?CarbonImmutable
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        foreach (self::DATE_FORMATS as $format) {
            $date = \DateTimeImmutable::createFromFormat('!' . $format, $value);
            $problems = \DateTimeImmutable::getLastErrors();

            if ($date && (!$problems || ($problems['warning_count'] === 0 && $problems['error_count'] === 0))) {
                return CarbonImmutable::instance($date);
            }
        }

        return null;
    }

    /** Case- and spacing-insensitive match against a fixed option list. */
    private static function matchOption(string $value, array $options): string
    {
        $squash = fn ($text) => strtolower(preg_replace('/\s+/', '', $text));

        foreach ($options as $option) {
            if ($squash($option) === $squash($value)) {
                return $option;
            }
        }

        return $value;
    }
}
