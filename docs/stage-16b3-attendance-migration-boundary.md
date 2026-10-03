# Stage 16B.3 attendance migration boundary

Stage 16B.3 adds live V2 attendance contracts only. It does not read, import, or alter V1 production attendance. A future historical migration needs a separately reviewed source schema, dry-run reconciliation, and approval before execution.

## Candidate mapping for a later certified migration

| Verified V1 evidence | V2 destination | Rule |
| --- | --- | --- |
| Company and employee identity | `attendance_sessions.company_id` and `employee_id` | Require an explicit, tenant-scoped V1→V2 identity map. Never match by name or email. |
| Original clock-in and clock-out instants | `attendance_sessions.clock_in_at` and `clock_out_at` | Preserve original evidence with a documented source timezone. Never use browser time or silently reinterpret a date-only value. |
| Original work date and timezone | `attendance_sessions.work_date` and `timezone` | Retain a certified source work date and IANA timezone. If unavailable or ambiguous, record an exception instead of guessing. |
| Verifiable original break intervals | `attendance_breaks` | Import only explicit, ordered intervals. Do not invent a break or close an open one. |
| Historical corrections and approvals | `attendance_correction_requests` and `attendance_revisions` | Preserve original values and the full actor/reason/time trail when verifiable. Do not overwrite original punches. |
| Source record identity | Separately approved migration provenance/mapping | Preserve exact, case-sensitive source identifiers and make import replay idempotent. Do not reuse live clock-action keys. |

Unmappable company/employee identities, missing or ambiguous timezone, invalid chronology, overlapping breaks, duplicate active sessions, conflicting source IDs, and unverifiable corrections must become migration exceptions for review. No historical record is to be treated as a new employee clock action. A future importer must not trigger payroll calculation, journal posting, payments, inventory, banking, or notifications. Late, early, overtime, absence, leave, and half-day classifications remain outside this contract.
