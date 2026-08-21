# User Import

Imports users from a CSV file into PostgreSQL through both a **React web UI** and a
**PHP CLI**, with the parsing, normalisation and validation logic shared between them.

Written for the Moodle Developer Coding Challenge (PHP). The brief is in
[`docs/`](docs/).

---

## 1. Overview

The import follows the flow set out in the brief:

```
Upload -> Parse -> Validate -> Preview -> Import
```

Both entrypoints call the same `App\Import\ImportService`, which owns the entire
pipeline. Neither the CLI nor the API re-implements any part of it.

```
CSV file ──> CsvReader ──> UserRecord ──> UserValidator ──> ImportService ──> PostgresUserRepository
             (line nos.)   (normalise)    (rules, dedupe)    (orchestrates)    (transaction, upsert)
```

| Path | What it does |
|---|---|
| `user_upload.php` | CLI: parse, validate, optionally import |
| `public/api/preview.php` | Accepts an upload, returns a dry-run report and a token |
| `public/api/import.php` | Accepts the token, re-reads the stored file, imports for real |
| `web/` | React UI (Vite) driving those two endpoints |

### Project layout

```
user_upload.php              CLI entrypoint (at the root, to match the brief's command)
src/
  Csv/CsvReader.php          Streams rows with accurate CSV line numbers
  Import/UserRecord.php      Value object; trims and normalises
  Import/UserValidator.php   Required fields, email format, in-file duplicates
  Import/ImportService.php   Orchestrates the flow; dry-run aware
  Import/{ImportReport,RecordResult,ValidationError}.php
  Db/Database.php            PDO factory, config injected
  Db/PostgresUserRepository.php
  Db/UserRepositoryInterface.php
  Config/Config.php          Reads env, falls back to .env
  Cli/{Arguments,ReportRenderer}.php
  Http/{Bootstrap,Json,UploadStore}.php
public/                      Document root: built React app + the two API endpoints
web/                         React source (Vite)
sql/schema.sql               Table definition used by --create-table
tests/                       PHPUnit suite
storage/tmp/                 Uploads awaiting import (gitignored, swept hourly)
```

---

## 2. Requirements

| Dependency | Version | Notes |
|---|---|---|
| PHP | 8.3+ | with `pdo_pgsql` and `mbstring` |
| PostgreSQL | 12+ | developed against 16 |
| Composer | 2.x | PSR-4 autoloading and PHPUnit |
| Node.js | 18+ | **optional** — only to modify the UI; the build is committed |

Runtime PHP dependencies: **none**. The only Composer requirement is PHPUnit, and it is
a dev dependency. Runtime JS dependencies are `react` and `react-dom`; `vite` and
`@vitejs/plugin-react` are dev dependencies.

### Checking your PHP

```bash
php -v                  # must be 8.3 or newer
php -m | grep pgsql     # must list pdo_pgsql
```

If `pdo_pgsql` is missing, enable it in the `php.ini` that `php --ini` reports and restart
your web server.

> **MAMP users:** the `php` on your `PATH` is often *not* MAMP's. Compare `which php` with
> the version Apache reports. On the machine this was developed on, the terminal `php` was
> Homebrew's 8.5 while Apache served MAMP's 8.3.30 — both have `pdo_pgsql`, and both satisfy
> "PHP 8.3+", but it is worth confirming rather than assuming. To pin the CLI to MAMP's
> build explicitly:
>
> ```bash
> /Applications/MAMP/bin/php/php8.3.30/bin/php user_upload.php --file users.csv
> ```

---

## 3. Installation and setup

```bash
git clone https://github.com/charlesalo/moodle-coding-challenge.git
cd moodle-coding-challenge

composer install          # PHP dependencies (PHPUnit)

cp .env.example .env      # then edit .env for your database

php user_upload.php --create-table
```

That is everything needed to run the CLI and the web UI.

**Node is not required to review this project.** The built React app
(`public/index.html` and `public/assets/`) is committed, so Apache can serve it straight
from a fresh clone. You only need Node if you want to change the UI:

```bash
cd web
npm install
npm run build             # emits into ../public
```

Asset filenames are stable (`app.js`, `app.css`) rather than content-hashed, so a rebuild
overwrites the previous output instead of leaving an orphaned file behind in git.

---

## 4. Database configuration

Connection details are read from the environment, falling back to a `.env` file in the
project root. Nothing is hard-coded, and `.env` is gitignored.

`.env.example`:

```
DB_HOST=localhost
DB_PORT=5432
DB_NAME=user_import
DB_USER=postgres
DB_PASS=
```

Real environment variables take precedence over `.env`, so the application also runs in CI
or under a process manager with no file present.

Create the database, then the table:

```bash
createdb user_import
php user_upload.php --create-table
```

The schema (`sql/schema.sql`):

```sql
DROP TABLE IF EXISTS users;

CREATE TABLE users (
    id         SERIAL PRIMARY KEY,
    name       VARCHAR(255) NOT NULL,
    surname    VARCHAR(255) NOT NULL,
    email      VARCHAR(255) NOT NULL UNIQUE,
    created_at TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);
```

> ⚠️ `--create-table` **drops the existing table**. The brief asks for a way to
> "create/rebuild" the table, so running it twice must leave a clean table rather than
> silently doing nothing. Do not run it against data you want to keep.

---

## 5. How to start the application

### Option A — everything served by Apache (how the submission is meant to be reviewed)

The built app is committed, so there is no build step. Point your web server's document root at `public/`, or browse to the project under an
existing root. With MAMP's default root and this folder inside `htdocs/`:

```
http://localhost:8888/<project-folder>/public/
```

The UI and the API are then on one origin, so no CORS configuration is involved.

### Option B — Vite dev server (for working on the UI)

```bash
cd web && npm run dev        # http://localhost:5173
```

`web/vite.config.js` proxies `/api` to Apache on port 8888, so the React app can hot-reload
on 5173 while PHP keeps serving the endpoints. Proxying is used rather than adding CORS
headers to PHP, because those headers would only ever exist for development.

Adjust the `target` in `vite.config.js` if your Apache port or project path differs.

---

## 6. How to use the web UI

1. **Upload** — choose a `.csv` file with a `name,surname,email` header row and press
   **Parse and validate**. The file is uploaded and validated; nothing is written yet.
2. **Preview** — the summary shows how many records were found, are valid and are invalid.
   The table lists every row with its CSV line number and its **normalised** values, so you
   can see capitalisation and lowercasing applied. Each failing row shows the specific
   reason it was rejected.
3. **Import** — press **Import N users**. Only the valid records are inserted. The button
   is disabled while a request is in flight, and when there is nothing valid to import.
4. **Result** — the final counts, including how many rows were skipped as already present.

Errors from the server (unreadable file, wrong header, database down) are shown in the page,
not just logged to the console.

---

## 7. How to use the CLI

```
php user_upload.php --file <filename> [--dry-run]
php user_upload.php --create-table
php user_upload.php --help
```

| Option | Effect |
|---|---|
| `--file <filename>` | CSV file to process. Also accepts `--file=<filename>`. |
| `--dry-run` | Parse and validate only. **Never writes to the database.** |
| `--create-table` | Create/rebuild the `users` table, then exit. Does not need `--file`. |
| `--help` | Print usage and exit 0. |

**Exit codes**

| Code | Meaning |
|---|---|
| `0` | Success — including a partial import where some rows were rejected |
| `1` | Fatal error — invalid arguments, missing/unreadable file, or a database failure |

A partial import is a success: invalid rows are reported, not treated as a failure of the
run. Running with no arguments at all prints usage and exits `1`.

---

## 8. Example CLI commands

Running against the sample `users.csv` (49 data rows, 9 of them deliberately broken).

### Rebuild the table

```console
$ php user_upload.php --create-table
The users table was created (any existing table was dropped).
```

### Dry run — validate without importing

```console
$ php user_upload.php --file users.csv --dry-run
Dry run: no changes were written to the database.

Users found: 49
Valid:       41
Invalid:     8

Validation errors (8):
  Line 42 [email]: Invalid email address format: "invalid-email".
  Line 43 [email]: Invalid email address format: "missing@".
  Line 44 [email]: Duplicate email address "john.smith@example.com", first seen on line 2.
  Line 45 [email]: Duplicate email address "john.smith@example.com", first seen on line 2.
  Line 46 [name]: Name is required.
  Line 47 [surname]: Surname is required.
  Line 48 [email]: Email is required.
  Line 49 [email]: Invalid email address format: "bad@@example.com".
```

### Real import

```console
$ php user_upload.php --file users.csv
Users found: 49
Valid:       41
Invalid:     8
Imported:    41
Skipped:     0

Validation errors (8):
  ... same eight errors as above ...

Imported 41 users successfully.
```

### Running it a second time

Every address is now already stored, so nothing is re-imported:

```console
$ php user_upload.php --file users.csv
Users found: 49
Valid:       0
Invalid:     49
Imported:    0
Skipped:     0

Validation errors (49):
  Line 2 [email]: A user with this email address already exists.
  ...
```

### Error handling

```console
$ php user_upload.php
No arguments given.
... usage ...                                            # exit 1

$ php user_upload.php --file
--file requires a filename, for example --file users.csv  # exit 1

$ php user_upload.php --nope
Unknown option "--nope".                                  # exit 1

$ php user_upload.php --file missing.csv
Error: CSV file not found: missing.csv                    # exit 1
```

---

## 9. Assumptions and design decisions

### No framework

The brief allows frameworks but prioritises correctness, simplicity and maintainability.
A framework would add routing, a service container and ORM conventions that a CSV importer
does not need, and would obscure the one thing actually worth showing: that the CLI and the
web UI genuinely share the same import logic. Plain PHP with PSR-4 autoloading keeps that
visible. The only dependency is PHPUnit, for tests.

### Pipeline order: trim → normalise → validate → dedupe

The order is not arbitrary; two rows in the sample file depend on it.

- **Line 50** (`spaces,test, spaces@example.com `) is only valid because trimming happens
  *before* validation — `filter_var` rejects an address with surrounding spaces.
- **Line 45** (`another,duplicate,JOHN.SMITH@EXAMPLE.COM`) is only detected as a duplicate of
  line 2 because lowercasing happens *before* duplicate detection.

Normalisation therefore lives in `UserRecord`'s constructor: a record cannot exist in a
non-normalised state, so nothing downstream can validate raw input by accident.

### Name capitalisation is deliberately simple

Names use `mb_convert_case(..., MB_CASE_TITLE)` after lowercasing, which is multibyte-safe
(`josé` → `José`, `иван` → `Иван`). It is not linguistically perfect:

| Input | Output | Ideally |
|---|---|---|
| `mcdonald` | `Mcdonald` | McDonald |
| `o'brien` | `O'brien` | O'Brien |
| `van der berg` | `Van Der Berg` | van der Berg |
| `mary-jane` | `Mary-Jane` | Mary-Jane ✓ |

The brief asks for capitalisation, not for correct handling of surname particles, which is
a genuinely hard problem with no rule that is right for every culture. A predictable rule
that a reviewer can reason about is worth more here than a heuristic that is right slightly
more often and surprising the rest of the time. The behaviour is pinned by tests, so it
cannot drift silently.

### Email validation, and the `filter_var` no-TLD question

Addresses are validated with `filter_var($email, FILTER_VALIDATE_EMAIL)`. There is a test
asserting directly that this rejects the brief's `john@example.com@example.com`, rather than
assuming it.

It is widely repeated that `filter_var` accepts a bare host such as `john@example` with no
TLD, which would call for a supplementary "domain must contain a dot" check. **On PHP 8.3
that is not true** — `filter_var` rejects both `john@example` and `john@localhost`. This was
verified rather than taken on faith, so no extra check was added; one would be dead code.
The behaviour is pinned by a data-provider test so a future PHP change would be caught.

### Error messages use CSV line numbers

Errors report the **line number in the file**, so line 42 is what a spreadsheet shows on
row 42, not a zero-indexed record position. Line numbers are counted from the file's raw
bytes rather than incremented once per record, so a quoted field containing a newline does
not shift every subsequent number.

### Preview-to-import handoff: a token, not the rows

The obvious approach is for the browser to POST the validated rows back to `import.php`.
This project deliberately does not do that, because it would insert client-supplied data
that could have been edited in the browser after validation, and it would duplicate
validation across the client/server boundary.

Instead:

1. `preview.php` saves the upload to `storage/tmp/{token}.csv` under a **server-generated**
   random token, runs `ImportService` in dry-run mode, and returns the report plus the token.
2. `import.php` receives **only the token**, re-reads that stored file, and runs the same
   `ImportService` for real.
3. The stored file is deleted after a successful import, and every request sweeps files
   older than an hour so nothing accumulates.

The token is matched against `/^[a-f0-9]{32}$/` before it is ever used to build a path, so a
client string is never interpolated into the filesystem. Uploads must also be a real
`is_uploaded_file`, carry a `.csv` extension, and fall under a 5 MB cap.

One consequence worth stating: because the file is re-validated at import time, the counts
can legitimately differ between preview and result if the database changed in between. That
is why the result screen reports *imported* and *skipped* separately from *valid*.

### `ON CONFLICT DO NOTHING` rather than check-then-insert

Inserts run inside one transaction using:

```sql
INSERT INTO users (name, surname, email) VALUES (...)
ON CONFLICT (email) DO NOTHING
RETURNING id
```

Checking for an existing address and then inserting leaves a gap in which a concurrent
import can insert the same address. Letting the database enforce its own unique constraint
closes that gap, and `RETURNING id` distinguishes a row that was actually written from one
that was skipped, which is what the *skipped* count reports.

Existing addresses are *also* looked up before the insert, so the preview and the report can
explain *why* a row will not be imported. The `ON CONFLICT` clause is the backstop for the
race, not the primary reporting mechanism.

### `--dry-run` reads, but never writes

The brief says a dry run "must not modify the database". It performs a **read-only** lookup
for addresses that already exist, so the preview can warn "would conflict" before you commit
to importing. No `INSERT`, `UPDATE` or `DELETE` is issued, and there is a test asserting the
repository's `insertMany` is never called.

If no database is reachable, a dry run degrades gracefully: it skips the conflict check, adds
a note saying so, and still reports parsing and validation results. This keeps the CLI and
the preview useful before Postgres is configured, and keeps the test suite runnable with no
infrastructure.

### Duplicates are rejected, never merged

A record whose address already exists — in the file or in the database — is reported as an
error. There is no update-existing-user behaviour; that is out of scope for the brief.
Within a file, the **first** occurrence wins and every later one is flagged, naming the
earlier line.

### Errors are surfaced, never leaked

`PDOException` is translated into a `DatabaseException` whose message names the host and
database but **never the password**, and no stack trace reaches the terminal or the browser.
The API endpoints install an exception handler that turns anything uncaught into a JSON
error. File-level CSV errors carry an optional display label, so the web UI reports
`users.csv` rather than the server path the upload was stored at.

A database failure is recorded on the report rather than thrown, which lets the CLI choose
its exit status and the API render a readable message while still returning the parse and
validation results.

### CSV assumptions

- Comma-delimited, UTF-8, with a **required** `name,surname,email` header row. A file whose
  header does not match is rejected outright, since silently importing mismatched columns is
  worse than refusing.
- A leading UTF-8 BOM is stripped. (The supplied `users.csv` has none, but uploads often do.)
- CRLF line endings are handled; trimming every field removes the stray `\r`.
- Configurable delimiters, encodings and column mapping are out of scope.
- A **file-level** problem (missing, unreadable, empty, bad header) aborts the run. A
  **row-level** problem, including the wrong number of columns, is reported against that row
  and does not stop the rest of the file importing.

### Argument parsing without `getopt()`

`getopt()` silently ignores anything it does not recognise, so `--nope` would be discarded
without complaint — but the brief explicitly requires handling "invalid command-line
arguments". `getopt()` also reads the real process `argv`, which makes the rules awkward to
test without spawning subprocesses. `App\Cli\Arguments` parses the token list directly,
which reports unknown options properly and is unit-testable.

---

## Tests

```bash
composer test                          # or: ./vendor/bin/phpunit
./vendor/bin/phpunit --exclude-group integration   # no database needed
```

The suite covers normalisation (including multibyte input and the documented capitalisation
limits), every validation rule, all nine planted rows in the sample file, CSV reader failure
modes, upload-token safety including path traversal attempts, CLI argument handling, and the
import service driven by an in-memory repository.

The headline assertion is that the shipped `users.csv` produces exactly **49 found, 41 valid,
8 invalid**, and that a dry run never calls `insertMany`.

Integration tests exercise the real SQL against PostgreSQL. They use their own database
(`DB_TEST_NAME`, default `user_import_test`) because they rebuild the table, and they skip
automatically when no database is reachable.

## What the sample file exercises

| Line | Content | Expected |
|---|---|---|
| 42 | `invalid,email,invalid-email` | Invalid email format |
| 43 | `missing,domain,missing@` | Invalid email format |
| 44 | `duplicate,user,john.smith@example.com` | Duplicate of line 2 |
| 45 | `another,duplicate,JOHN.SMITH@EXAMPLE.COM` | Duplicate of line 2, **after lowercasing** |
| 46 | `,noname,noname@example.com` | Name is required |
| 47 | `noname,,missing.surname@example.com` | Surname is required |
| 48 | `missing,email,` | Email is required |
| 49 | `bad,format,bad@@example.com` | Invalid email format |
| 50 | `spaces,test, spaces@example.com ` | **Valid**, after trimming |
