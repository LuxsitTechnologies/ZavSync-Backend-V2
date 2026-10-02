# Stage 15A.4 — manual MariaDB test certification

Baseline: 95ab2fb244caf082b3014d7ad8456f734f7739c5.
The user manually verified MariaDB 11.8.9 migrations (80), development seed,
one user and one company. Codex did not connect to MariaDB or Docker.

## Local verification

- Harness safety/configuration tests: PASS, 17 tests / 23 assertions.
- Stage 15 on SQLite: PASS, 66 tests / 466 assertions.
- Complete regression on SQLite: PASS, 528 tests / 3,099 assertions.
- Laravel Pint, changed PHP syntax, Composer strict validation and diff/whitespace:
  PASS. No dependencies changed. No credentials added; test fixtures use synthetic
  unsafe targets. Normal application configuration and schema remain untouched.
- MariaDB suite, concurrency, collation and date/time runtime checks: NOT EXECUTED
  by Codex. User-reported schema/seed success is distinct from test certification.

## Harness and safety

### Stage 15A.4.2 handler lifecycle correction

The user manually ran Stage 15 on MariaDB 11.8.9: 66 tests / 466 assertions,
zero failures/errors, but 66 risky tests. This is NOT a clean certification PASS.
The previous PHPUnit bootstrap booted a preflight Laravel application and left its
global error/exception handlers installed. Application flush did not restore them.
Laravel test teardown clears handler stacks through HandleExceptions::flushState;
PHPUnit therefore detected removal of handlers present before each test. Restored
baseline handler state made this repeat across otherwise unrelated test bodies.

The runner now executes preflight in a separate bounded child process, waits for
successful exit, then launches a fresh PHPUnit process. Explicit preflight mode
boots/guards/terminates Laravel and exits. Its handlers and shutdown callbacks
cannot leak into PHPUnit. PHPUnit's bootstrap only loads Composer, checks process
settings and enables certification mode: it does not boot Laravel or touch global
handlers. Each test uses Laravel's normal TestCase lifecycle and retains the
per-application server/cache guard before any RefreshDatabase operations. The
runner also uses --fail-on-risky; no reporting is disabled or suppressed.

Tests exercise actual preflight with only database connectivity faked, unchanged
parent/sentinel handlers, and a nested PHPUnit process using the generated config
and real Laravel lifecycle with --fail-on-risky --fail-on-warning. Its two probe
tests (4 assertions) pass with zero risky tests. The root-level probe fixture is
deliberately outside the normal Unit/Feature discovery directories; its fake
connection forbids PDO access and exists only in child test processes.

Manual commands below are unchanged. Both stage15 and all run isolated preflight
automatically; direct invocation of a generated XML is not the certification entry
point. MariaDB must be rerun with zero failures, errors AND risky tests before PASS.

Stage 15A.4.2 local gates PASS: harness 31 tests / 68 assertions; Stage 15 SQLite
66 / 466; full SQLite regression 542 / 3,144. Nested probe: 2 / 4, zero risky
tests (included as a subprocess check, not added to the outer test count). Pint,
changed PHP syntax, Composer strict validation and diff/whitespace review PASS.
No actual MariaDB, Docker, or production connection was made by Codex.

### Stage 15A.4.1 bootstrap correction

The first manual preflight exposed a harness bug before tests: routesAreCached()
requires the container's files binding, which is registered during provider
registration, not by bootstrap/app.php construction. The original early call was
invalid. This was a harness failure, not MariaDB test execution.

Preflight now checks file_exists() on Laravel's resolved config/route cache paths
before kernel bootstrap, with no filesystem-service dependency and without loading
either cache file. A supported beforeBootstrapping(LoadConfiguration) hook repeats
the check after environment loading, catching cache-path overrides from dotenv
before cached configuration or routes can be consumed. The normal console kernel
then completes bootstrap. Framework cache-state methods and the database guard
run only after services exist. Safety checks are retained, not suppressed.

Regression coverage boots the real application in a child PHP process and mocks
only its database connection (getPdo forbidden). It verifies filesystem binding
availability and the exact server assertion query without contacting MariaDB.
Separate subprocess tests reject unsafe process values and existing cache files.
Normal SQLite application creation remains unchanged.

Stage 15A.4.1 local verification: harness 29 tests / 58 assertions; Stage 15
SQLite 66 / 466; complete SQLite regression 540 / 3,134, all PASS. Pint, changed
PHP syntax, Composer strict validation and diff/whitespace review PASS. No
MariaDB, Docker or production access; manual certification remains pending.

Normal phpunit.xml supplies sqlite and :memory: defaults. Those env entries do not
use force, so process environment can override them, but relying on that alone is
not a safe destructive certification workflow. There is no CreatesApplication
override or .env.testing file. Laravel's installed base TestCase boots the app;
RefreshDatabase runs migrate:fresh once per process, then wraps tests in rollback
transactions. LazilyRefreshDatabase delays that same initialization until needed.
The previously seeded disposable records will be erased. Do not expect tests to
leave development seed data behind. DDL can implicitly commit on MariaDB.

Run tests/mariadb.php, not ordinary artisan test, for this certification. It derives
a temporary PHPUnit XML from the committed normal configuration on every run:
same suites, source coverage, testing environment and safe settings, but no DB_ XML
defaults. Absolute paths avoid temporary-file path resolution errors. Credentials
are inherited only through process environment, never written to XML. The temporary
file is deleted on normal exit. No extra CLI options or parallel execution accepted.

Before any Laravel bootstrap, the runner requires explicit reset consent and the
exact mysql/127.0.0.1/33079/zavsync_v2_stage15_cert/zavsync_stage15 target. It rejects
URL/socket overrides. Bootstrap rejects cached configuration/routes. The configured
connection is checked again (including read/write overrides) before a read-only
SELECT DATABASE(), VERSION(). Only MariaDB 11.8.9 is accepted, with sanitized errors.
The check is repeated on each application creation before RefreshDatabase starts.
An initial bootstrap failure stops PHPUnit before test execution, including Unit tests.
Keep the database user restricted to this disposable database as defense in depth.
This protects accidental targeting, not deliberate modification of the harness.

Mail uses array, cache/session array, queues sync, and FBR submission defaults OFF.
Unfaked Laravel HTTP requests are blocked; resolving real SMTP transport is blocked.
Existing per-test FBR/email/AI fakes remain responsible for their expected responses.
No successful provider response is fabricated globally. Do not add direct socket,
cURL, Guzzle or SMTP calls that bypass these test boundaries. No provider runtime
certification is implied by these suites.

## Exact manual commands (normal macOS Terminal)

Use a subshell to keep credentials out of the parent environment. Do not enable
shell tracing. The private credential file must contain trusted shell assignments.
No password is printed or supplied as a command-line argument.

```sh
(
  set -e
  set +x
  cd /Users/humzamazhar/Documents/ChatGPT/ZavSync-Backend-V2
  source "$HOME/.zavsync-stage15-mariadb.env"
  : "${DB_USER_PASS:?Private DB_USER_PASS is missing}"
  export APP_ENV=testing
  export DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=33079
  export DB_DATABASE=zavsync_v2_stage15_cert DB_USERNAME=zavsync_stage15
  export DB_PASSWORD="$DB_USER_PASS" DB_URL='' DB_SOCKET=''
  export MARIADB_CERTIFICATION_RESET=zavsync_v2_stage15_cert
  php tests/mariadb.php preflight
  php tests/mariadb.php stage15
  php tests/mariadb.php all
)
```

The preflight performs read-only server assertions, not migrations. Each suite
repeats preflight automatically, so invoking either suite separately remains guarded.
Stop on failures; do not weaken assertions or substitute SQLite. If cached config
or routes are detected, stop and clear those generated caches in a separate approved
local step, never concurrently with tests. No automatic cache deletion is performed.
Record both complete PHPUnit summaries and failures. Runtime certification is pending.

## SQLite/engine assumption audit

- No SQLite PRAGMA, sqlite_master, strftime, INSERT OR, or in-memory-specific SQL
  found in existing tests. No test switches database connection or uses parallel
  test setup. The new safety tests deliberately mention SQLite to reject it.
- Most feature tests use RefreshDatabase; five accounting test classes use the
  lazy equivalent. Unit tests without persistence do not establish engine behavior.
- MigrationIdentifierCompatibilityTest, EncryptedStorageCompatibilityTest and
  Stage15ReadinessTest include static schema/grammar mocks. Their PASS is not a
  runtime DDL certification even when the surrounding suite uses MariaDB.
- MariaDB strict typing, collation, savepoints, constraint exceptions, JSON behavior
  and implicit DDL commits remain runtime risks. Serial passing suites do not prove
  locks, concurrent uniqueness or deadlock retries. Do not claim those certified.
- There are no business, schema, constraint, FBR payload or accounting changes.

## Concurrency runtime test specifications — prepared, NOT EXECUTED

These are separate two-worker certification scenarios, not additional checks run
by the serial regression command. Use independently booted PHP workers/connections,
committed fixture setup (not a parent RefreshDatabase transaction), deterministic
barriers and bounded timeouts; terminate/join workers and clean only fixture rows.
Both workers must apply the same target/server guard. Use a shared local fake FBR
call recorder, never real transport. Record server isolation level and lock timeout.

| Scenario | Coordination and required assertion |
| --- | --- |
| SELECT FOR UPDATE / company lock | A locks company; B tries same lock; prove B cannot pass barrier until A commits; separate company remains usable |
| Invoice numbering | Concurrent creation for one company with distinct keys: distinct increasing sequence/number, two complete documents, zero financial effects |
| Source-company mapping | Race same source into different companies: exactly one mapping, loser explicit conflict, no cross-tenant rows |
| Concurrent historical import | Race same source/fingerprint: one canonical crosswalk/document per source ID, no overwritten evidence, reconciled counts |
| Duplicate import | Replay after completion and after interrupted chunk: same targets, no duplicates, zero gateway calls |
| FBR submission claim | Pause fake transport after first claim; second worker must not send while claim is pending |
| Idempotency-key races | Same key/same payload returns same operation; same key/different payload conflicts; no extra record or side effect |
| Pending-attempt lease | Active lease rejects retry; expired eligible lease retries original key/payload only; late first completion cannot corrupt accepted state |
| Rollback after exception | Inject failure mid-import chunk; inspect from second connection: no partial chunk, previous committed chunks preserved; resume reconciles |
| Deadlock/retry | Opposite lock order with bounded barriers: capture victim/SQLSTATE; only documented retries, no duplicated numbers/attempts; no blanket PASS for an unhandled deadlock |

Current Stage15 serial tests cover replay, lease validation, uniqueness conflicts
and exception rollback, not overlapping processes. Full concurrent certification
requires implementing/executing these worker scenarios separately.

## Collation runtime test specifications — NOT EXECUTED

Proposed desired policy: preserve original identifier spelling; opaque source IDs,
idempotency keys and provider references compare exactly (case/accent/space
sensitive); source_system should be canonicalized only by an explicitly approved
source contract, not implicitly by DB collation. Generated invoice numbers are
canonical uppercase; historical numbers must not be silently merged by case.
This is a proposed certification policy, not an approved constraint change.

Current mysql config defaults to utf8mb4_unicode_ci; migrations do not specify
binary identifier collations. This can differ from SQLite and exact PHP comparisons.
Capture information_schema column collations first. In rollback-isolated fixtures
test equality and unique insertion for AbC/abc, e/é, 01/1 and trailing-space pairs
for invoice_number, source_system/source_id, creation/submission idempotency_key,
and FBR reference columns. Verify tenant scope and any reference fields that are
not DB-unique separately. Report observed aliases/conflicts against policy; do not
alter indexes/collations or normalize historical values to force a pass.

## Date/time runtime test specifications — NOT EXECUTED

Capture PHP/app timezone, server/system/session timezone and SQL mode. Use explicit
fixtures around UTC midnight, Asia/Karachi midnight, year-end and leap day. Assert
invoice_date and supplied due_date remain identical Y-m-d through import, raw DB,
model and API. Existing null due-date fallback is intentional and must be reported
separately, not confused with a timezone shift. Assert original V1 created/updated
strings remain unchanged in legacy_original_timestamps/evidence JSON. Verify native
submission created/completed times, last_attempt_at, import started/completed and
migration timestamps against frozen instants and documented precision (seconds).
Repeat selected cases with a non-UTC session; document timestamp vs datetime
conversion, and do not invent a timezone for ambiguous V1 timestamps.

## Accounting firewall

Stage15 includes AccountingFirewallTest and PakistanFbrDomainTest: historical
imports and new FBR draft/submit/retry operations must leave native invoices,
journals/lines, customer payments, inventory movements/transactions, banking
reconciliations and other operational tables empty. Historical gateway call count
must be zero. Cross-domain IDs remain rejected in both directions. Existing tests
exercise the same application paths on the selected engine without reconnecting
the invoice domains. Banking reconciliation assertions are supplemented by the
unchanged source boundary: no financial account or banking posting service is
invoked. Concurrency scenarios must repeat the same firewall assertions.
