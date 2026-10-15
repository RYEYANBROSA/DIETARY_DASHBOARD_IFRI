<?php

namespace App\Support;

use Illuminate\Support\Facades\Validator;

/**
 * One SWS respondent, as entered on the form or read from an upload. The
 * columns are the SWS transmission template's headers (CITY, DISTRICT, ...
 * IDP_AGE), stored lower-cased in sws_data. Both paths go through check(), so
 * a record is cleaned and validated the same way however it arrives:
 *
 *   names, area and address  upper case, extra spaces removed
 *   contact number           digits only; 9xxxxxxxxx (Excel drops the 0) and
 *                            639xxxxxxxxx become 09xxxxxxxxx
 *   email                    lower case
 *   latitude / longitude     decimal degrees, checked against their ranges
 *
 * A respondent is identified by SWS_MEMBER_NO.
 */
final class SwsRecord
{
    /** Template columns in order - the upload's header row, lower-cased. */
    public const COLUMNS = [
        'city', 'district', 'barangay',
        'identifier1', 'identifier2', 'identifier3',
        'sws_member_no', 'lastname', 'firstname', 'suffix', 'middlename',
        'floor_no', 'building_no', 'block', 'street_name', 'subd',
        'contact_number', 'email_address', 'latitude', 'longitude',
        'indexwomenname', 'idp_age',
    ];

    /** Column => label for the form, the data view and messages. */
    public const LABELS = [
        'city' => 'City',
        'district' => 'District',
        'barangay' => 'Barangay',
        'identifier1' => 'Identifier 1',
        'identifier2' => 'Identifier 2',
        'identifier3' => 'Identifier 3',
        'sws_member_no' => 'SWS member no.',
        'lastname' => 'Last name',
        'firstname' => 'First name',
        'suffix' => 'Suffix',
        'middlename' => 'Middle name',
        'floor_no' => 'Floor no.',
        'building_no' => 'Building no.',
        'block' => 'Block',
        'street_name' => 'Street name',
        'subd' => 'Subdivision',
        'contact_number' => 'Contact number',
        'email_address' => 'Email address',
        'latitude' => 'Latitude',
        'longitude' => 'Longitude',
        'indexwomenname' => 'Index woman\'s name',
        'idp_age' => 'IDP age',
    ];

    /** Columns kept as typed apart from trimming (codes, coordinates, email). */
    private const VERBATIM = ['sws_member_no', 'contact_number', 'email_address', 'latitude', 'longitude', 'idp_age'];

    /**
     * Validate raw input and return [record ready to store, errors keyed by column].
     */
    public static function check(array $input): array
    {
        $data = [];
        foreach (self::COLUMNS as $column) {
            $value = $input[$column] ?? '';
            $value = is_string($value) || is_numeric($value) ? trim((string) $value) : '';
            if (!in_array($column, self::VERBATIM, true)) {
                $value = mb_strtoupper(preg_replace('/\s+/u', ' ', $value));
            }
            $data[$column] = $value;
        }

        $data['sws_member_no'] = mb_strtoupper(preg_replace('/\s+/u', '', $data['sws_member_no']));
        $data['email_address'] = mb_strtolower($data['email_address']);
        $data['contact_number'] = self::phone($data['contact_number']);

        $text = fn (int $max) => ['nullable', 'string', "max:{$max}"];
        $validator = Validator::make($data, [
            'city' => ['required', 'string', 'max:100'],
            'district' => $text(100),
            'barangay' => ['required', 'string', 'max:100'],
            'identifier1' => ['required', 'string', 'max:100'],
            'identifier2' => $text(100),
            'identifier3' => $text(100),
            'sws_member_no' => ['required', 'string', 'max:50', 'regex:/^[A-Z0-9][A-Z0-9_\-\/.]*$/'],
            'lastname' => ['required', 'string', 'max:100'],
            'firstname' => ['required', 'string', 'max:100'],
            'suffix' => $text(20),
            'middlename' => $text(100),
            'floor_no' => $text(50),
            'building_no' => $text(50),
            'block' => $text(50),
            'street_name' => $text(150),
            'subd' => $text(150),
            'contact_number' => ['nullable', 'regex:/^\d{7,11}$/'],
            'email_address' => ['nullable', 'email', 'max:150'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'indexwomenname' => $text(150),
            'idp_age' => ['nullable', 'integer', 'between:0,120'],
        ], [
            'sws_member_no.regex' => 'The SWS member no. can only have letters, numbers and - _ / . (like SWS_001).',
            'contact_number.regex' => 'The contact number must be 7 to 11 digits, like 09171234567.',
            'idp_age.integer' => 'The IDP age must be a whole number of years.',
        ], array_map('strtolower', self::LABELS));

        $errors = array_map(fn ($messages) => $messages[0], $validator->errors()->toArray());

        if ($errors !== []) {
            return [null, $errors];
        }

        $record = $data;
        $record['idp_age'] = $data['idp_age'] === '' ? null : (int) $data['idp_age'];

        return [$record, []];
    }

    /** A stored row as form input. */
    public static function toForm(object|array $row): array
    {
        $row = (array) $row;
        $form = [];
        foreach (self::COLUMNS as $column) {
            $form[$column] = (string) ($row[$column] ?? '');
        }

        return $form;
    }

    /** "LASTNAME, FIRSTNAME" for messages and history lines. */
    public static function name(array $record): string
    {
        return trim(($record['lastname'] ?? '') . ', ' . ($record['firstname'] ?? ''), ', ');
    }

    /**
     * Digits only. A 10-digit number starting with 9 had its leading 0 dropped
     * by Excel; +63 / 63 mobile numbers are written the local way.
     */
    private static function phone(string $value): string
    {
        $digits = preg_replace('/\D/', '', $value);

        if (strlen($digits) === 10 && str_starts_with($digits, '9')) {
            return '0' . $digits;
        }
        if (strlen($digits) === 12 && str_starts_with($digits, '639')) {
            return '0' . substr($digits, 2);
        }

        return $digits;
    }
}
