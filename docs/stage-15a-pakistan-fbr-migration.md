# Stage 15A — Pakistan/FBR domain and migration contracts

## Domain boundary

Native accounting remains in `invoices`, `invoice_lines`, `customer_payments`, `fbr_submission_attempts`, `journals` and `journal_lines`. Its models and accounting lifecycle retain `InvoiceService`, `InvoiceCalculationService`, `InvoicePostingService`, `CustomerPaymentService`, `AccountsReceivableService`, `JournalPostingService` and period locks. Existing `/api/v1/accounting/*` routes operate only on native accounting records. The existing accounting FBR adapter remains available for those records.

Pakistan/FBR documents use `PakistanFbrInvoice`, `PakistanFbrInvoiceLine` and `PakistanFbrSubmissionAttempt`, backed by `pakistan_fbr_invoices`, `pakistan_fbr_invoice_lines` and `pakistan_fbr_submission_attempts`. They use `PakistanFbrInvoiceService`, `PakistanFbrSubmissionService` and `PakistanFbrPayloadMapper`. They have no journal, payment, stock or posting relationships, and no post/payment endpoints. Their amounts do not enter accounting invoice queries or AR reports.

Shared primitives are company/user context, UUIDs, RBAC, the existing invoicing entitlement, AuditService, the stateless integer InvoiceCalculationService, the FbrGateway/HttpFbrGateway transport, FbrSubmissionContext, FbrSubmissionStatus and response sanitation. Sharing the calculator has no lifecycle or database side effects. The transport idempotency namespace identifies both the owning domain and invoice UUID.

FBR company settings (`fbr_company_configurations`) and versioned reference values (`fbr_reference_values`) are shared provider infrastructure. No new users, permissions engine, queue engine, audit engine or accounting system is introduced.

## Historical documents

LegacyInvoiceImportService targets only Pakistan/FBR tables. Historical documents have `document_state=HISTORICAL`, `is_historical=true` and `historical_accounting_state=DOCUMENT_ONLY_UNPOSTED`. V1 draft/sent and FBR status remain independent facts. New native accounting tables have no migration columns.

Migration identity and operations use `legacy_import_runs`, `legacy_entity_maps` and `migration_exceptions`. Crosswalks contain source system/entity/ID, company and explicit target model/UUID. A source company cannot be remapped to another tenant or combined into an already mapped target company. Run identity binds the export fingerprint and source company. Changed source records generate SOURCE_RECORD_CHANGED rather than overwriting evidence.

`legacy_fbr_evidence` belongs exclusively to Pakistan/FBR invoices and is separate from both domains' live submission attempts. All historical evidence is submission-blocked, including failed or unsubmitted documents and success-without-reference cases. Evidence and historical financial fields are immutable. Resolving an exception does not unlock submission or change evidence.

Duplicate historical references are preserved verbatim and flagged for review, rather than silently removed. No automatic amendment, cancellation or manual override to historical submission is provided.

Headers and lines retain independently stored amounts and original decimal strings/timestamps. Buyer snapshots are independent of customer linkage. A unique in-company tax-identity match may link an existing customer; ambiguous matches remain unlinked with an exception. No clients, users, historical payments or opening journals are imported.

## New Pakistan/FBR lifecycle

Create draft → calculate integer totals → edit eligible draft → explicit submit → pending → accepted/submitted/rejected/failed.

Creation uses a required Idempotency-Key, a company lock and a separate PKF numbering sequence. Historical numbers do not consume the native accounting sequence. Request totals, company IDs, document state and journal fields cannot override server-owned fields.

Accepted/submitted/in-flight/uncertain documents are immutable. Rejected drafts may be corrected and submitted using a new key. Unavailable-provider retries require the original key and identical payload hash. A short in-flight lease prevents immediate duplicate calls; remote provider idempotency behavior still requires staging certification. Success without a reference stays submitted/pending confirmation.

FBR_PAKISTAN_SUBMISSION_ENABLED defaults to false. A deployment must certify provider payload/decimal-string acceptance, real reference values and provider idempotency before enabling it. Endpoints are configured by operators through FBR_SANDBOX_API_ENDPOINT/FBR_PRODUCTION_API_ENDPOINT, never through invoice input. Tokens are per company, encrypted and omitted from resources and audit values. Switching environment without supplying a replacement token clears the old token. HTTPS and TLS verification are required; redirects are disabled.

The mapper emits exact decimal strings from integer money/quantity. Input `other_tax_rate_bps` represents extra tax and `advance_tax_rate_bps` represents further tax in this domain; neither represents an accounting payment. Historical values are never passed through the calculator. Scenario IDs, FED, 236G/236H, certified print/QR, amendments and cancellations remain explicitly unsupported pending certification. Local reference seed rows are labeled LOCAL_FIXTURE and are not regulatory certification.

## HTTP contracts for Stage 15B

All routes below start with `/api/v1/pakistan-fbr`, require authenticated company context through X-Company-Id, and use the existing invoicing entitlement.

| Route | Permission | Contract |
| --- | --- | --- |
| GET /invoices | pakistan_fbr.view | Paginated documents; optional historical boolean and per_page 1–100 |
| POST /invoices | pakistan_fbr.manage | Create calculated draft; Idempotency-Key required |
| GET /invoices/{id} | pakistan_fbr.view | Document detail, buyer snapshot, lines, lifecycle and capability flags |
| PATCH /invoices/{id} | pakistan_fbr.manage | Full draft payload; eligible documents only |
| POST /invoices/{id}/submit | pakistan_fbr.submit | Explicit provider submission; Idempotency-Key required |
| GET /invoices/{id}/attempts | pakistan_fbr.view | Sanitized live attempt history |
| GET /configuration | fbr.configuration.view | Seller/environment/state and credential_configured; never the credential |
| PUT /configuration | fbr.configuration.manage | Validated seller/environment fields and optional replacement credential |
| GET /reference-data | fbr.configuration.view | Optional category and include_inactive filters; source/version metadata |
| GET /migrations | migration.view | Company import runs |
| GET /migrations/{run} | migration.view | Progress and saved reconciliation |
| GET /migrations/{run}/exceptions | migration.view | Company-scoped exception ledger |
| PATCH /migration-exceptions/{id} | migration.manage | resolution_state RESOLVED/IGNORED plus required resolution_note |
| GET /historical-invoices/{id}/evidence | migration.view | Immutable, sanitized V1 FBR evidence |

Draft payload: invoice_date, optional due_date, invoice_type, sale_type, origin_province, destination_province, optional customer_id/notes; buyer_snapshot with registration_number, name, type (Registered/Unregistered), province and optional address; lines containing description, hs_code, unit, quantity_milli, unit_price, tax_rate_bps, fbr_rate_id, optional discount, additional tax-rate basis points and SRO identifiers.

All API money values are integer minor units, quantities integer thousandths, and rates integer basis points. Document responses explicitly identify `domain=pakistan_fbr` and `accounting_integration=NOT_INTEGRATED`. Historical source metadata appears only for migration.view. Historical paid amounts are evidence, not an operational customer balance. A configured submission flag does not assert provider availability.

Responses use established Laravel validation (422), authorization/entitlement (403), tenant-scoped missing record (404), idempotency conflict (409), and sanitized provider unavailable (503) handling. Migration execution has no tenant-facing HTTP endpoint. Existing accounting FBR URLs must not be silently repointed to Pakistan/FBR models.

## Local/staging operations

Use an explicitly supplied JSON export with source_system, manifest, invoices, invoice_items and fbr_invoice_submissions. Decimal values must be strings or integers; floats, unsupported precision and integer overflow are rejected. Input must be a bounded regular .json file under LEGACY_MIGRATION_INPUT_ROOT (default storage/app/private/legacy-imports). CSV, serialized PHP, scripts and arbitrary paths are not supported. Orphan and malformed source structures are rejected.

An authorized operator provides an existing target company UUID, V1 company ID and actor user ID:

```sh
php artisan legacy:invoice-import /approved/private/root/snapshot.json --company=TARGET_UUID --source-company=4 --actor=USER_ID --dry-run --no-interaction
php artisan legacy:invoice-import /approved/private/root/snapshot.json --company=TARGET_UUID --source-company=4 --actor=USER_ID --no-interaction
php artisan legacy:invoice-import /approved/private/root/snapshot.json --company=TARGET_UUID --source-company=4 --actor=USER_ID --resume=RUN_UUID --no-interaction
php artisan legacy:invoice-import /approved/private/root/snapshot.json --company=TARGET_UUID --source-company=4 --actor=USER_ID --reconcile-only --no-interaction
```

Dry-run produces mappings, conversion totals and exceptions without writes. Import is chunked, transactional and resumable. Reconcile-only recomputes the report and emits machine-readable exceptions without writes. The CLI verifies operator company authority and entitlement. A COMPLETED run means processing ended; it does not mean exceptions are resolved or migration certified.

Reconciliation compares source/target identities, individual header and line amounts, aggregate totals, quantities, statuses, FBR references, original timestamps and crosswalk coverage. It reports unresolved exceptions separately from preservation discrepancies. Run each explicitly approved company mapping and aggregate the per-company reports against the supplied global manifest during staging. Global users/clients/company-count manifest expectations are retained as review context, not evidence that those master records were migrated.

The synthetic acceptance fixture verifies 128 invoices, 128 lines, 121 submissions, 115 successes, six failures, 112 successful references, three ambiguous successes and 2/1/2 financial mismatches. It also verifies the 7/38/6/77 invoice/FBR status matrix. These are fixtures, not production imports or universal business rules.

## Future accounting integration

A separately approved integration command could explicitly select an eligible non-historical Pakistan/FBR document and create a native accounting draft via InvoiceService. A dedicated company-scoped link and idempotency record would record both document UUIDs. It would require accounting permissions, independent approval, posting-date validation and period locks, then call InvoicePostingService and JournalPostingService through their normal workflow. No Eloquent observer, FBR submission callback or import hook should post accounting automatically.

Historical cutover balances require a separate reconciled opening-balance process. Stage 15A does not create integration links, opening journals or historical journal replay.

## Certification boundary

Local tests use SQLite and synthetic exports/fake HTTP providers. No production snapshot, production DB, FBR sandbox/production delivery, production queue/scheduler, staging rehearsal, MySQL/MariaDB concurrency or production cutover is certified here. Staging must validate the exact production snapshot plus uncommitted source delta, all company mappings and global manifest totals, every known exception, reference-data versions, credential management, provider idempotency, concurrent retries, backup/restore and rollback. Printing/QR and legal invoice fields need verified provider requirements before frontend implementation.
