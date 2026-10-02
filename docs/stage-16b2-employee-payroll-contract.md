# Stage 16B.2 employee payroll self-service contract

This contract is backend-only. The existing V2 `PayrollEntry`, its immutable calculated lines, the batch posting record, and employee payment allocations remain authoritative. No V1 payroll record is imported or published by this change.

## Authority and identity

- Every request uses `auth:sanctum` and the active `X-Company-Id` resolved by the `company` middleware.
- Employee reads require the `payroll` entitlement and `employee.payroll.view`. The server resolves the active `CompanyUser` for the authenticated user, then its explicit `employee_id` link. An unlinked membership returns `409 EMPLOYEE_IDENTITY_NOT_LINKED`; no email, name, or employee-code match is attempted. A platform administrator has no self-service bypass.
- Release requires the separate `payroll.release` permission and the normal `payroll` entitlement. `employee.payroll.view` cannot release or use administrative payroll APIs. Existing roles are not automatically granted either new permission; an administrator must assign them deliberately. The demo seed assigns both to its finance-administrator role.

## Employee disclosure lifecycle

An entry is employee-visible only when `payroll_entries.released_at` is non-null. Posting, payment, approval, and mere existence of an entry never publish it. `POSTED`, `PARTIALLY_PAID`, or `PAID` with a posting journal and `posted_at` is the prerequisite for release, not a substitute for release. The administrator action locks the company, batch, and entry in the established order, writes `released_at` and `released_by` once, and records one `employee_payslip_released` audit event inside the same transaction. Repeating the action returns the original metadata without another write or audit event.

Release does not recalculate, approve, post, settle, or pay payroll, and does not mutate any accounting or banking record. Existing rows, including future V1 historical imports, default to unreleased. There is no automatic historical publication.

This stage defines no unrelease endpoint. If an already released batch is subsequently reversed, the historical disclosure remains readable and its current `batch_status` is `CANCELLED`; the employee is not silently shown a still-valid payslip. A separately reviewed correction/notification and retraction policy remains deferred.

## HTTP endpoints

| Endpoint | Permission | Result |
| --- | --- | --- |
| `POST /api/v1/payroll/entries/{entry}/release` | `payroll.release` | `200` with `entry_id`, `released_at`, `released_by`; `422 PAYROLL_NOT_POSTED` before a valid posting; foreign IDs `404`. |
| `GET /api/v1/employee/payroll` | `employee.payroll.view` | Paginated, released entries belonging to the linked employee in the active company. `per_page` defaults to 12 and is limited to 1–50. Ordered by release time and ID descending. |
| `GET /api/v1/employee/payroll/{entry}` | `employee.payroll.view` | One released, self-owned entry; unreleased and foreign IDs both return `404`. |

The employee list uses Laravel pagination (`data`, `links`, `meta`). The detail is an unwrapped JSON object, consistent with the application's `JsonResource::withoutWrapping()` policy. The client never supplies an employee ID. No unindexed year/search filter is added.

## Employee response allowlist

Both reads expose `id`, `released_at`, safe company name/ID, the payroll snapshot's employee name/code/department/designation, batch number/current status/posted timestamp, period name/start/end/pay date, currency, and the entry's stored integer-minor-unit `base_salary`, `gross_earnings`, `employee_deductions`, `employee_contributions`, `tax_amount`, `reimbursements`, and `net_pay`. They also expose `paid_amount`, `outstanding_amount`, and `payment_status` (`UNPAID`, `PARTIALLY_PAID`, `PAID`) derived solely from the entry's persisted payment allocations. Detail additionally exposes allowlisted earnings/reimbursement and employee deduction/contribution/tax lines with code, name, type, and integer amount.

The response never serializes `profile_snapshot`, statutory rule snapshots, tax identifiers, bank references, GL/liability mappings, journal IDs or internals, employer contribution lines, administrative adjustments, correction reasons, or other employees/batches. Stored payroll numbers are not recalculated by this API. Payment dates and bank/mode labels are not supplied because the current employee-safe contract does not establish them. Printable rendering may consume structured data, but no server PDF, certified payslip document, or download API is introduced.

An active membership, explicit link, permission, and released entry continue to permit a terminated or resigned employee to read historical payslips. Employment status alone does not revoke access.

## V1 and portal parity

| Capability | V1 / portal reference | V2 authority | Stage 16B.2 treatment |
| --- | --- | --- | --- |
| Own payroll history | V1 `myHistory`; portal monthly list | `PayrollEntry` plus explicit company membership link | Self-only, paginated, release-filtered API. |
| Payslip breakdown | Portal earnings/deductions and print view | Persisted `PayrollEntryLine` and entry totals | Safe structured detail; frontend implementation later. |
| Release visibility | V1 history did not enforce employee publication | New explicit `released_at` / `released_by` | Administrator-controlled disclosure independent of posting. |
| Payment status | Portal shows paid date/status | `PayrollPaymentAllocation` | Allocation-based state/amount only; no invented paid date or bank mode. |
| Salary revision history | Portal mock section | Profile effective dates, not a certified employee-safe revision contract | Deferred. |
| PDF/download | Portal button | No certified V2 employee PDF service | Deferred. |

Frontend Stage 16B.2 integration is not started. The portal must use the two self endpoints, not the administrative reporting endpoint or client-side employee filtering. Existing V1 production data migration and MariaDB 11.8.9 publication certification remain separate gates.
