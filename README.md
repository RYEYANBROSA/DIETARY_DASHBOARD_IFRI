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
| `validator@dietary.test` | Validator | Dashboard, F11, SWS Upload Data, My Profile |
| `client@dietary.test` | Client | Dashboard, F72, My Profile |

The seeder only runs when `APP_ENV=local`, so these accounts never reach production. Running it again resets them
to the values above, password included. See `database/seeders/DatabaseSeeder.php`.

## Modules and default access

| Module | Administrator | Validator | Client | What it does |
|---|---|---|---|---|
| Dashboard | ✓ | ✓ | ✓ | Survey-wide figures and researcher performance |
| F11 Encoding & Upload | ✓ | ✓ | | Encode / upload household members (`f11`) |
| SWS Upload Data | ✓ | ✓ | | Add / edit / upload SWS transmission records, view all of them (`sws_data`) |
| F72 Food Recall | ✓ | | ✓ | Read-only coverage, households, recalls, food lines, downloads (`f72`) |
| My Profile | ✓ | ✓ | ✓ | Own name, mobile number, password |
| System Users | ✓ | | | Add accounts, set levels, switch modules on/off |

In **System Users**, administrators can switch any module on or off for each account. System Users itself is the
exception: only administrators can have it. Administrators always have every module.

### Dashboard - Overview

The Overview puts the map first. One filter at the top, **Area** (province), also carries over to the 24-hr
recall tab.

- **SWS households map.** Every household with coordinates appears on the map, coloured by its 24-hr recall
  status: Day 1 and 2 completed, Day 1 completed, refused, or not yet visited. Each status's count is in the
  legend. Each pin opens the household's details, with **Open in SWS** for accounts that have that module. A
  household is matched to its recalls by identifiers 1–3 + SWS member no. Street and Satellite views are both
  available.
- **Key figures:**
  - household coverage
  - Day 1 and Day 2 recall completion (out of SWS respondents)
  - Day 1 refusal
  - SWS records transmitted
- **Latest activity:** recent F11 and SWS uploads, edits and transmissions, and 24-hr recalls received.
- **Researchers · 24-hr recall:** each researcher's Day 1 and Day 2 completion.
- **What people ate · by meal** and **Most reported foods**, from the F72 food lines.

### Dashboard - 24-hr recall monitoring

A second Dashboard tab tracks the 24-hour food recall. It reads `f72_interview_status`, which the field researchers'
tablets fill.

- **Completed** = `interview_status` 1 or 2. **Refused** = 3 and above.
- Every rate is out of the **SWS respondents** (`sws_data`) in the same area. The recall table's province /
  municipality / barangay match `sws_data`'s city / district / barangay. A recall sent more than once counts once,
  using its latest update.
- **For each recall day:** completion rate, refusal rate, and how many are not yet done.
- **Day 2 follow-up:** how many Day 1 completers have also completed Day 2.
- **Household survey vs 24-hr recall:** completion and refusal side by side for the same area. The household
  survey is `household_listing`, where covered = status 1–2 and refused = status 4.
- **Recalls per day transmitted.**
- **By researcher:** click a row to filter by that researcher.
- **By area:** province › district › barangay. Click a row to drill down.
- **Filters:** date transmitted (from / to), province, municipality / district, barangay, researcher, and recall
  day.
- **The table is read from the live server** (`RECALL_MONITOR_CONNECTION=mysql_live`); set it to `survey` to use
  a local copy instead.
  - Rows are read once and reused for 2 minutes, so changing filters doesn't query the live server each time.
    **Refresh**, next to "24-hr recall from the live server · updated …", reads them again straight away.
  - Before any query, a 3-second check on the server's port runs. If the server is out of reach, both tabs say so
    at once, with **Try again**, instead of waiting about 30 seconds for a timeout. The rest of the Overview (map,
    SWS, food) keeps working. A failure is remembered for a minute before the server is tried again.
  - The test suite always uses an in-memory copy (`phpunit.xml`).

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

### SWS Upload Data (validators)

The SWS transmission data, one record per respondent, in `sws_data`. Its columns are the SWS template's variable
names: CITY, DISTRICT, BARANGAY, IDENTIFIER1–3, SWS_MEMBER_NO, LASTNAME, FIRSTNAME, SUFFIX, MIDDLENAME, FLOOR_NO,
BUILDING_NO, BLOCK, STREET_NAME, SUBD, CONTACT_NUMBER, EMAIL_ADDRESS, LATITUDE, LONGITUDE, INDEXWOMENNAME and IDP_AGE.

- **All data**: every column in one wide table for reading through large amounts of data.
  - The header row stays put while you scroll. SWS member no., last name and the Edit / History buttons stay
    pinned while you scroll sideways.
  - Filter by city, district, barangay, who added or edited a record, or one upload. Search covers names, codes,
    street, contact and email.
  - Sort by clicking a column header. Show 25, 50, 100 or 250 rows per page.
  - Hide columns you don't need. The choice is remembered in your browser.
  - **Export CSV** downloads everything that matches the filters, with the template's headers.
- **View map**: every household with a LATITUDE / LONGITUDE, plotted with the same filters as All data.
  - Click a household for its details, **Edit record**, **History** or **Google Maps**.
  - Households at exactly the same spot share one marker, and its popup lists all of them.
  - Switch between **Street** (OpenStreetMap) and **Satellite** (Esri) at the top right.
  - Records without coordinates and points outside the Philippines are counted above the map. Those outside are
    often swapped latitude / longitude. "List them" opens the records without coordinates in All data.
  - The pin on each All data row opens the map zoomed in on that household.
  - Up to 5,000 households are drawn at once; narrow the filters to see more. The map needs internet access for
    its tiles. Leaflet itself is bundled, and only the map tab loads it.
- **Transmit to live server**: sends SWS records to the live database (`mysql_live`, `DB_*_LIVE` in `.env`, its
  `sws_data` table). The field researchers' tablets read from there.
  - The badge on the button counts records not on the live server yet.
  - Pressing it asks for confirmation, then sends every *not transmitted* record that matches the current filters.
    Records are inserted on the live server, or updated there when it already has the same identifiers and
    SWS_MEMBER_NO.
  - The live server's `sws_data` must use the same key. `php artisan sws:live-key` reports how it is keyed now;
    `php artisan sws:live-key --apply` changes it, once. Until then, Transmit refuses to send, so households
    sharing a member no. can't overwrite each other on the live server.
  - Each record has **TRANSMITTED** (yes / no) and **TRANSMITTED_AT**. A record becomes *yes* only after the live
    server accepts it.
  - **Transmitted records are locked.** Their edit button turns into a grey lock that explains why when clicked.
    Opening one from the map, saving a form opened before it was sent, and upload rows that would change it are
    all refused; an identical upload row is simply skipped. New records can still be added and transmitted.
  - Filter by **Transmitted** to see what has or hasn't been sent. History shows each transmission and who made it.
  - If the live server can't be reached, nothing more is marked as transmitted, and an alert says so.
  - Each transmission that sends records raises the live server's **`listing_version`**: 1 when the table is empty,
    then last version + 1 (2 becomes 3). That tells the tablets there is new listing data. A transmission with
    nothing to send leaves the version alone. It never blocks a transmission: if `listing_version` is missing or
    can't be updated, the data is still sent and the alert says the version wasn't raised.
- **Add a record**: one respondent on a form.
  - Names, area and address are saved in capitals and email in lower case.
  - Contact numbers are saved as digits. A 9xxxxxxxxx number that lost its leading 0 in Excel becomes 09xxxxxxxxx.
  - Latitude and longitude are range-checked.
  - **IDENTIFIER1 + IDENTIFIER2 + IDENTIFIER3 + SWS_MEMBER_NO together identify a record**, so the same member no.
    can be used in different households, but not twice in the same one.
- **Upload file**: the SWS template as **CSV or Excel (.xlsx)**, up to 5,000 rows.
  - The first row must be the variable names. The SWS transmission file itself can be uploaded as it is.
  - Rows are checked first. A row whose identifiers and SWS_MEMBER_NO are already here updates that record; anything else is added.
  - Download the template with the **SWS template** button, or from `/sws/template`.
- Like F11, records are never deleted, only edited. Who added or edited each record, by form or upload, is shown
  beside it, and **History** lists every change as before → after. The history is stored in `sws_activities` and
  `sws_changes`.

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

## Alerts

All messages use SweetAlert2 (`resources/js/alerts.js`):

- **Success:** a dialog that closes itself after a few seconds. Quick repeated actions, like ticking a module,
  show a small corner toast instead. Results that matter, like a file check or an import, wait for **OK**.
- **Error, warning and info:** a dialog with **OK**.
- **Confirmations:** for example "Import N records?", with **Cancel** and the action button. These replace the
  browser's plain `confirm()` box.
- **Uploads:** a modal shows the upload progress, then "Checking every row…". Then it shows the result: an error
  if the file can't be used, a warning if some rows have problems, or a success.

From a Livewire component, call `$this->dispatch('toast', type: 'success'|'error'|'warning'|'info', message: '...')`.
You can also pass `title:`, `persist: true` or `small: true`. In Blade, call `notify({...})` or
`confirmAlert({...}).then(ok => ...)`.

## Accounts

- Registration creates a **Client** account. The email is the username and has to be verified by email.
- Passwords need 8+ characters with upper- and lowercase letters, a number and a special character.
- An account created by an administrator gets one welcome email with a link to set its own password.
- First real administrator: register normally, then run `php artisan user:make-admin you@example.com`.

## Databases

- `DB_*` holds this app's own tables: accounts, sessions, cache, and the F11 activity trail (`f11_activities`).
- `SURVEY_DB_*` holds the survey tables, in the final DDCS schema: `f11`, `f72`, `household_listing`, `LocalArea`,
  plus `sws_data` for SWS Upload Data.
  In development both point at `dietary_dashboard`. Production can point `SURVEY_DB_*` at another database.
- `php artisan survey:install-schema` creates any survey table that is missing. Existing tables are left as they
  are.
- `php artisan survey:copy-from ddcs_dashboard` copies another database's survey rows in. In development this is
  used for dummy data. Only columns that both databases have are copied. Add `--fresh` to replace rows that are
  already there.
- `DB_*_LIVE` is the live server (`mysql_live`) the field researchers' tablets read. Only SWS Upload Data's
  **Transmit** writes to it. The test suite refuses to run unless it points at in-memory SQLite.
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
