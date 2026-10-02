# Stage 15A.1 — FBR Invoicing parity and certification boundary

## Baseline and evidence

Baseline: `d1056475edb3447c21bb6e89d989157e91a41601`, parent
`43eeba7c25685e726c1e2df25d3c42f32f6bfceb`, clean `main`, backend origin
`LuxsitTechnologies/ZavSync-Backend-V2`. No production access is authorized.
This matrix precedes implementation changes. The supplied specification establishes
production counts and historical conditions, but does not supply the modified live
V1 source snapshot. GitHub V1 alone is not authoritative. Accordingly, source-level
payload transformations, registration checking and regulatory output are NOT certified.

Classification: A exact evidenced parity; B supported with known differences;
C missing; D unsafe legacy implementation requiring safe replacement; E obsolete;
F production snapshot/provider confirmation required. F is not a claim of absence.

## Functional parity matrix

| Feature | Current foundation | Class / remaining evidence |
| --- | --- | --- |
| Invoice list, create, detail | Dedicated tenant-scoped FBR documents | B/F: independent UUID API; live UX/filters require snapshot |
| Draft save/reopen/edit | Dedicated DRAFT state and immutable submitted documents | B: stricter safety; live state and FBR state preserved independently on import |
| Buyer name/address/tax ID/type | Buyer snapshot; NTN 7 / CNIC 13 digits | B/F: local validation, not authoritative registration check |
| Buyer registration checking | No external registry lookup | C/F: provider contract required |
| Invoice type, provinces, sale type | String fields and categorized reference records | B/F: supported, catalog semantics uncertified |
| HS code, description, UOM, FBR rate | Per-line persisted fields | B/F: catalog association/format requires live confirmation |
| Quantity, rate/unit price | Integer thousandths and minor units | B: intentional exact arithmetic; live rounding requires confirmation |
| Sales/extra/further/withholding tax | Integer amounts and basis-point calculation | B/F: field mapping present; statutory calculation semantics uncertified |
| SRO schedule/item | Per-line identifiers | B/F: dependent catalog validation uncertified |
| Scenario/reference identifiers | V1 buyer-type derivation: Registered SN001, Unregistered SN002; SCENARIO catalog remains uncertified | B/F: no client selector |
| Submit/status/response/reference | Dedicated attempts, sanitized response, explicit status | B/F: fake-gateway verified, real provider not certified |
| Failure/retry/idempotency | Original-key retry for uncertain attempts; pending lease | B/F: application safety; upstream idempotency must be certified |
| Invoice/submission history | Audit, attempts and historical evidence | B/F: no fabricated event history |
| Reference data | Global versioned categories with source provenance | B/F: local fixtures are not regulatory data |
| Reference synchronization | No live provider sync | C/F: staging prerequisite |
| Company configuration | Encrypted credential, environment, seller identity/address/province | B: secure replacement; never expose plaintext credentials |
| Print data | Detail contains authoritative stored buyer, lines, totals, reference | B/F: seller-at-issue and exact regulatory layout need evidence |
| QR | No generated regulatory QR | C/F: deliberately uncertified |
| Historical invoices | Separate immutable FBR documents and evidence | A for supplied preservation/isolation requirements; F for real export certification |

No feature is classified obsolete without evidence. No source-level exact parity is
asserted merely because field names resemble V1.

## Domain and compatibility decision

FBR Invoicing is a separate module from Native V2 Accounting Invoicing.
FBR Invoices do not automatically post to V2 Accounting.
Internal `PakistanFbr*`, `pakistan_fbr_*`, and `/api/v1/pakistan-fbr/*` names remain:
renaming persisted tables/routes provides no functional benefit and risks clients.
The product name is **FBR Invoicing**.

Native `Invoice` / `InvoiceLine` and `/api/v1/accounting/*` retain their accounting
services, AR, payments, period locks and journal posting. Dedicated FBR services
share only pure calculation, transport, company configuration, authorization and
audit infrastructure; they never call accounting posting services.

The existing native `/accounting/fbr/invoices/{invoice}/submit` remains a legacy
compatibility API because existing clients/tests use it and live parity is not yet
certified. Its payload differs from the dedicated mapper. Do not expand or use this
path in Stage 15B. Retirement requires client inventory, dedicated-provider
certification and a separately approved transition. Historical reads must survive.

## Field-by-field payload report

For ALL rows below, actual V1 source and transformation are **not supplied**:
confirm against the modified production snapshot before provider certification.
The V2 sources below describe code, not certified statutory semantics.

| Provider field | V2 source / transformation | Parity / certification |
| --- | --- | --- |
| invoiceType | invoice_type unchanged | F: live enum |
| invoiceDate | invoice_date formatted YYYY-MM-DD | F: live date semantics |
| invoiceRefNo | invoice_number unchanged | F: reference semantics |
| sellerNTNCNIC | company configuration seller_tax_identifier | F: identity rules |
| sellerBusinessName | configuration seller_business_name | F |
| sellerProvince | invoice origin_province | F: compare configured province/live origin |
| sellerAddress | configuration seller_address | F |
| buyerNTNCNIC | buyer_snapshot.registration_number | F: registration lookup |
| buyerBusinessName | buyer_snapshot.name | F |
| buyerProvince | invoice destination_province | F |
| buyerAddress | buyer_snapshot.address | F |
| buyerRegistrationType | buyer_snapshot.type | F: enum |
| scenarioId | Derived from buyer_snapshot.type: Registered SN001, Unregistered SN002 | B/F: V1 rule verified; provider certification pending |
| hsCode | line hs_code unchanged | F: catalog |
| productDescription | line description unchanged | F |
| rate | line fbr_rate_id unchanged | F: identifier versus display value |
| uoM | line unit unchanged | F: catalog |
| quantity | quantity_milli exact decimal with 3 places | B/F: rounding |
| valueSalesExcludingST | taxable_amount exact decimal with 2 places | B/F |
| salesTaxApplicable | tax_amount exact decimal with 2 places | B/F |
| extraTax | other_tax_amount exact decimal with 2 places | B/F |
| furtherTax | advance_tax_amount exact decimal with 2 places | B/F |
| salesTaxWithheldAtSource | withholding_tax_amount exact decimal with 2 places | B/F |
| sroScheduleNo | sro_schedule_id unchanged | F: catalog |
| sroItemSerialNo | sro_item_id unchanged | F: catalog |
| saleType | line sales_type unchanged | F: catalog |

## References and Stage 15B contract

PROVINCE, DOCUMENT_TYPE, HS_CODE, UOM, SALE_TYPE, RATE, SRO_SCHEDULE,
SRO_ITEM and SCENARIO representation: IMPLEMENTED. Certified content and real
synchronization: STAGING CERTIFICATION REQUIRED. Scenario invoice selection:
NOT YET SUPPORTED. Seed records are LOCAL_FIXTURE, never certified FBR data.

Use only `/api/v1/pakistan-fbr` for FBR workflows: GET/POST `invoices`,
GET/PATCH `invoices/{invoice}`, POST `invoices/{invoice}/submit`,
GET `invoices/{invoice}/attempts`, GET `reference-data`, GET/PUT `configuration`,
GET `historical-invoices/{invoice}/evidence`, and authorized migration/exceptions
APIs. Preserve X-Company-Id and idempotency headers. Never use accounting IDs.
Details are the authoritative invoice rendering data contract; capability flags
must govern unavailable features. Do not fabricate QR or treat current company
configuration as a historical seller-at-issue snapshot.

Reference lookup additionally accepts `parent_code`, `source_version`, and
`effective_on=YYYY-MM-DD` (inclusive validity bounds). Omitted filters preserve the
existing behavior. Permission remains `fbr.configuration.view`; the catalog is
global reference data, not company-owned business records. Draft/detail resources
identify `module_name: FBR Invoicing`, `print_data: true`, regulatory print status
`STAGING_CERTIFICATION_REQUIRED`, null QR content and unavailable registration/sync.

Stage 15B must implement FBR Invoicing using the V2 design system and the finalized
FBR Invoicing APIs. Backend certification gaps must remain honest unavailable states.

## Stage 15A.7 dedicated FBR contract

All monetary request and response fields below use integer PKR paisa; quantity uses
integer thousandths. New FBR line and invoice totals equal taxable amount plus
sales tax plus extra tax plus further tax. Sales tax withheld is reported separately
and never subtracted. The verified V1 dedicated line and HRM header use this rule;
the V1 dedicated header omitted extra/further tax and is deliberately corrected for
new V2 FBR Invoices. Native Accounting retains its own calculation service.
An optional positive integer `sales_tax` on each line is authoritative even when
it differs from the rate-derived result. Zero or omission follows the verified V1
fallback and calculates sales tax from `tax_rate_bps`. The stored rate metadata
(`tax_rate_bps` and `fbr_rate_id`) remains unchanged. Differences between an
explicit amount and rate-derived amount are accepted; a future warning/rejection
would require a verified regulatory rule. No floating-point arithmetic is used.

Create: `POST /api/v1/pakistan-fbr/invoices` with `X-Company-Id` and
`Idempotency-Key` headers. Update draft: `PATCH /api/v1/pakistan-fbr/invoices/{invoice}`.
Both accept `invoice_date` and optional `due_date` as YYYY-MM-DD, string
`invoice_type`, `sale_type`, `origin_province`, `destination_province`, optional
tenant-owned UUID `customer_id`, optional `notes`, and `buyer_snapshot` containing
`name`, `type` (Registered or Unregistered), optional 7-digit NTN or 13-digit CNIC
`registration_number`, optional `province` and `address`. `lines` is a nonempty
array of `description`, `hs_code`, `unit`, integer `quantity_milli`, integer
`unit_price`, optional integer `discount`, integer `tax_rate_bps` (sales tax),
optional integer `sales_tax` (positive exact amount overrides rate; zero, null or omission uses rate),
`fbr_rate_id`, and optional `sro_schedule_id`/`sro_item_id`. Optional integer
`extra_tax`, `further_tax`, `st_withheld` are exact line amounts. Their legacy
rate alternatives `other_tax_rate_bps`, `advance_tax_rate_bps`, and
`withholding_tax_rate_bps` remain supported when the corresponding explicit
amount is absent; a nonzero rate and explicit amount together are rejected.
`scenario_id` is never an input: the backend derives SN001 for Registered buyers
and SN002 for Unregistered buyers from the stored buyer type. The detail resource
exposes the derived `scenario_id`; the payload mapper emits `scenarioId`.

List: `GET /api/v1/pakistan-fbr/invoices` supports `historical` boolean and
`per_page` (1–100); detail is `GET /api/v1/pakistan-fbr/invoices/{invoice}`.
Additional free-text, status and date-range filters are deferred pending an
indexed query contract; the existing tenant-scoped pagination is unchanged.
Resource totals and per-line tax amounts are authoritative integer minor units.
Each line response exposes `sales_tax`, `extra_tax`, `further_tax` and `st_withheld` aliases
alongside the existing `other_tax_amount`, `advance_tax_amount` and
`withholding_tax_amount` fields for existing clients.
Submit: `POST /api/v1/pakistan-fbr/invoices/{invoice}/submit` requires an
`Idempotency-Key`; retry recovery:
`POST /api/v1/pakistan-fbr/invoices/{invoice}/retry` requires no client key.
The server selects the unresolved attempt under the invoice lock, then reuses its
original provider identity through the normal claim, payload-hash, configuration,
lease and generation checks. An active lease returns pending without a provider
call. Accepted, submitted, historical or never-submitted invoices cannot be
recovered this way. `GET /api/v1/pakistan-fbr/invoices/{invoice}/attempts`
returns status and sanitized evidence, without credentials or idempotency keys.
The invoice resource exposes `capabilities.retry_recovery` when an unresolved
pending or failed state has no accepted reference. A pending lease may still
defer the provider call; the server remains authoritative.

Configuration remains `GET/PUT /api/v1/pakistan-fbr/configuration`; reference
data remains `GET /api/v1/pakistan-fbr/reference-data`, including source, version,
active state and effective dates. Historical evidence is read through
`GET /api/v1/pakistan-fbr/historical-invoices/{invoice}/evidence` and remains
immutable. The resource advertises regulatory print/QR as uncertified and does
not authorize accounting posting or payment effects.

Historical V1 header and line amounts, tax amounts, withholding and evidence are
preserved independently; mismatch exceptions are recorded, never normalized.
Scenario values beyond the two verified V1 buyer types and regulatory catalog
data remain uncertified. No real provider behavior is certified by these tests.

## Historical and certification boundary

Imports preserve original header/line amounts, mismatches, invoice status,
timestamps and evidence separately. Success without a reference is not confirmed
acceptance and is never resubmittable. Supplied 128/128/121 acceptance counts are
fixture expectations, not business rules or a real production migration.

MARIADB RUNTIME CERTIFICATION: NOT EXECUTED.
Real provider, registration lookup, reference synchronization, regulatory print/QR
and production migration certification: NOT EXECUTED. No production cutover approval.

## Corrections and MariaDB static review

The two overlong foundation index names now have explicit short names. A follow-up
migration adds `legacy_entity_maps_target_unique` on source system, entity type and
target ID. This makes the crosswalk one-to-one in both directions; conflicting
existing crosswalks cause migration failure rather than silent data deletion.
Company mapping check/create now executes inside a company-row lock transaction;
the global source constraint protects races across different target-company locks.
Conflict exceptions are recorded after rollback. Chunk rollback/resume is preserved.
These tests verify constraints and sequential conflict behavior, NOT simultaneous
MariaDB worker execution.

Legacy native submission now treats Submitted-without-reference as terminal for
automatic retries, even with a new key. Completion/error lock order is consistently
invoice then attempt. Existing clients remain supported; no accounting posting is
introduced by the compatibility endpoint.

MySQL-grammar compilation covers every foundation index/foreign-key name plus the
new target index with a 64-character bound, without opening a database connection.
UUIDs compile through the mysql driver, FK creation order follows dependencies,
JSON/booleans/timestamps use framework schema types. Composite string indexes are
within modern InnoDB's 3072-byte limit for utf8mb4 with the configured empty prefix.
Certification must use that configuration and verify actual row format/index limits.
Default utf8mb4_unicode_ci compares text case-insensitively unlike SQLite: certify
case-variant source identifiers, invoice numbers and idempotency keys explicitly.
Do not silently normalize historical values to resolve collisions. FBR references
are intentionally not globally unique: historical duplicate evidence must be
preserved and reported, not discarded by a constraint. Reference acceptance does
not prove regulatory validity. Real row-lock, deadlock, rollback and simultaneous
import/submission behavior require disposable MariaDB 11.8.9 testing.

## Local verification results

Executed sequentially: no prior PHPUnit/Artisan process; optimize clear;
fresh migration and development seed on a dedicated temporary SQLite database;
focused suite **66 tests / 466 assertions**; complete regression **506 tests /
3,047 assertions**, zero failures/errors; Pint; strict Composer validation;
Composer audit (no advisories, approved network retry after sandbox DNS failure);
PHP syntax scan (825 files); 399 routes including 14 dedicated FBR routes; zero
duplicate route names; route cache create, route boot and clear.

Final scope review covers accounting firewall, historical no-submit, cross-domain
IDs, tenant/RBAC/entitlement checks, mapping constraints, import resume/idempotency,
submission retry/lease/configuration checks, secret/TLS scans, integer-money and
direct-posting scans, TODO/FIXME, whitespace and artifacts. The only floating-point
scan match in the Stage 15 tests is intentionally rejected input in the converter
test. No credentials, database dumps or generated verification artifacts are added.

These local results do not certify live V1 payload transformations or provider
behavior. SQLite tests cannot certify InnoDB locking, case-insensitive collation
or simultaneous workers. A separate disposable MariaDB certification stage is
appropriate next; staging migration and production cutover are not approved.
