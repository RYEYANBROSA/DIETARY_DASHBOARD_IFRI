# Dietary Dashboard (DOST-FNRI)

A web dashboard for the DOST-FNRI dietary survey (DDCS). It replaces the old `ddcs_dashboard`.

- Validators encode or upload **F11** household members.
- Clients and administrators review **F72** food recall data, survey coverage and downloads.

Built with Laravel 12, Livewire 3 and Tailwind CSS 4, using design Option A (forest-green sidebar).

## Demo accounts (local only)

`php artisan db:seed` creates one verified, active account per level. All three use the password **`Demo@2026!`**.

| Email | Level | Modules |
|---|---|---|
| `admin@dietary.test` | Administrator | Dashboard, F11, F72, My Profile, System Users |
| `validator@dietary.test` | Validator | Dashboard, F11, My Profile |
| `client@dietary.test` | Client | Dashboard, F72, My Profile |

The seeder only runs when `APP_ENV=local`, so these accounts never reach production. Running it again resets them
to the values above, password included. See `database/seeders/DatabaseSeeder.php`.

## Modules and default access

| Module | Administrator | Validator | Client | What it does |
|---|---|---|---|---|
| Dashboard | ✓ | ✓ | ✓ | Survey-wide figures and researcher performance |
| F11 Encoding & Upload | ✓ | ✓ | | Encode / upload household members (`f11`) |
| F72 Food Recall | ✓ | | ✓ | Read-only coverage, households, recalls, food lines, downloads (`f72`) |
| My Profile | ✓ | ✓ | ✓ | Own name, mobile number, password |
| System Users | ✓ | | | Add accounts, set levels, switch modules on/off |

In **System Users**, administrators can switch any module on or off for each account. System Users itself is the
exception: only administrators can have it. Administrators always have every module.

### Dashboard

- Headline figures:
  - households listed and coverage (interview status 1–2 counts as covered)
  - F11 members
  - F72 food lines and researchers
- Breakdowns:
  - household interview status
  - food lines by meal
  - weighing method
  - households by municipality
  - most reported foods
- Researcher performance: day 1 and day 2 food lines per researcher, with search.

### F11 Encoding & Upload (validators)

- **Encoded members**: list every member with area filters and search, and edit one. **Members are never deleted**:
  a wrong record is corrected by editing it, and every edit is kept in its history.
- **Encode a member**: a form with every F11 field.
  - Area dropdowns come from `LocalArea` plus the household listing.
  - Picking a listed household fills in identifier 1–3.
  - Age is worked out up to the reference date. Children under 5 get it in `age_months`, everyone else in
    `age_years`.
  - Values are stored the way the field tablets write them: names in capitals, member no. `01`, birthdate
    `8/27/1990`, reference date `05-01-2026`.
  - A member is identified by province, municipality, barangay, identifier 1–3 and member no. The same member
    can't be encoded twice.
- **Upload CSV**:
  1. Download the template (**CSV template** button, or `/f11/template`) and fill in one member per row.
  2. Choose the file. Every row is checked first, and you see how many members are new, how many are updates and
     each problem by line and column.
  3. Import. Existing members are updated and the rest are added. Rows with problems are left out.

  The column guide on the page lists the accepted values and date formats. Uploads are limited to 10 MB and 5,000
  rows.
- **Who changed what (for validation):**
  - Every member in the list shows who added it (encoded or uploaded) and who last edited it, and when.
    Members with no history are marked **Field tablet**: they came from the tablets or the dummy-data copy.
  - **History** opens a member's full trail: each add or edit, by whom, when, and whether it came from the
    form or an upload (with the file name). Edits list every changed field as before → after.
  - The list can be filtered by **Added / edited by** (an account) or **Upload** (one CSV file).
    **View members** under an upload in "Recent F11 activity" does the same.
  - Saving a member without changing anything leaves no history entry.
  - Per-member history is stored in `f11_changes`. The one-line activity feed is `f11_activities`. Both are in the
    app's database, because the `f11` table follows the tablet schema and has no room for it.

### F72 Food Recall (read-only)

- Filters: province, municipality, barangay, recall day, and a search box.
- Views:
  - **coverage by barangay**: click a barangay to drill into it
  - **households**: day 1 / day 2 members recalled, and a ZIP download per household
  - **members recalled**: names from F11
  - **food lines**
- Downloads for the current filter (the same files the old dashboard produced):
  - **Merged F72 CSV**: one row per food line with its household listing, F11 member and f72 columns side by side.
  - **All tables (ZIP)**: each survey table as a CSV plus the merged report.
  - **Per barangay & household (ZIP)**: a folder per barangay with a sub-folder per household. Pick a province
    first.

## Accounts

- Registration creates a **Client** account. The email is the username and has to be verified by email.
- Passwords need 8+ characters with upper- and lowercase letters, a number and a special character.
- An account created by an administrator gets one welcome email with a link to set its own password.
- First real administrator: register normally, then run `php artisan user:make-admin you@example.com`.

## Databases

- `DB_*` holds this app's own tables: accounts, sessions, cache, and the F11 activity trail (`f11_activities`).
- `SURVEY_DB_*` holds the survey tables, in the final DDCS schema: `f11`, `f72`, `household_listing`, `LocalArea`.
  In development both point at `dietary_dashboard`. Production can point `SURVEY_DB_*` at another database.
- `php artisan survey:install-schema` creates any survey table that is missing. Existing tables are left as they
  are.
- `php artisan survey:copy-from ddcs_dashboard` copies another database's survey rows in. In development this is
  used for dummy data. Only columns that both databases have are copied. Add `--fresh` to replace rows that are
  already there.
- **`php artisan migrate:fresh` drops every table in the database, including the survey tables** while they share
  `dietary_dashboard`. Run the two commands above again afterwards.

## Run locally

```
composer install
npm install && npm run build
php artisan migrate
php artisan db:seed                           # demo accounts (local only)
php artisan survey:install-schema
php artisan survey:copy-from ddcs_dashboard   # optional dummy data
php artisan serve --port=8001
```

`APP_URL` must match the address people open, because links in emails are built from it.

## Tests

```
php artisan config:clear
php artisan test
```

Tests run on in-memory SQLite, set in `phpunit.xml`. A cached config (`bootstrap/cache/config.php`) overrides that
and points tests at the real database, where `RefreshDatabase` would wipe it. `tests/TestCase.php` therefore stops
the run unless both connections are in-memory SQLite.
