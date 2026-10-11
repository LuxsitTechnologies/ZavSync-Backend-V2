# Zeeshan QA backend remediation: local final report

Baseline: `c4f2bfa9f8659ad0845a4dc26fea91dd374a1eb8`. Source: `Zavsync from WhatsApp Business.xlsx`, Sheet1 rows 2–24. This report covers the locally certified backend candidate; no publication is claimed. The 23-row V1/V2 evidence matrix is `zeeshan-v1-v2-backend-parity.md`; the frontend handoff is `zeeshan-backend-frontend-contract.md`.

## All 23 observations

| QA | Disposition | Backend result or remaining owner |
|---|---|---|
| 1 | FIXED BACKEND; FRONTEND FOLLOW-UP | V1-compatible customer phone syntax/digit count in Accounting and CRM customer handoff; UI must distinguish phone from contact name. |
| 2 | NEEDS DEV EVIDENCE | New-company login: V2 membership/company-selection contract exists; obtain redacted failure before changing auth. |
| 3 | FRONTEND | Dedicated FBR submit/retry APIs already exist; expose lifecycle-aware button. |
| 4 | FRONTEND | Backend permits alphanumeric employee department/designation labels. |
| 5 | BLOCKED POLICY | Full beneficiary-bank contract requires approved encrypted fields, masking, authority and payroll-use policy. |
| 6 | FIXED BACKEND; FRONTEND FOLLOW-UP | Company settings and CRM account websites accept safe bare domains as HTTPS. |
| 7 | FRONTEND | Announcement expiry is enforced by backend; UI needs calendar/timezone presentation. |
| 8 | FIXED BACKEND; FRONTEND FOLLOW-UP | Narrow, permissioned, company-scoped task-assignee lookup supplies employee UUIDs. |
| 9 | FRONTEND | Clarify FBR submission confirmation wording. |
| 10 | DEFERRED POLICY | Overnight-shift cross-date/DST/attendance/payroll semantics unapproved; V1 also disallows overnight store. |
| 11 | FRONTEND | V2 shift-swap consent/admin decision and employee asset-request workflows already exist. |
| 12 | FRONTEND | Render expense-category boolean as human-readable status. |
| 13 | NEEDS DEV EVIDENCE | Employee portal uses explicit member→employee link; do not infer it. |
| 14 | NEEDS DEV EVIDENCE | Company portal uses active membership/selected-company authority. |
| 15 | FRONTEND | Expose permissioned encrypted FBR company configuration, not a raw token read. |
| 16 | FRONTEND | Explain configuration environment/credential state, separate from reference data. |
| 17 | FIXED BACKEND; FRONTEND FOLLOW-UP | Same task-assignee UUID lookup as #8; backend revalidates writes. |
| 18 | FRONTEND | FBR register uses dedicated FBR invoices, not native Accounting invoices. |
| 19 | BLOCKED POLICY/EXTERNAL SOURCE | Official complete HS catalogue is not in V2; source, licensing, freshness and secure sync require approval. |
| 20 | IMPLEMENTED / INTEGRATION PENDING | Invitation-only role listing filters archived, foreign and over-privileged roles; complete role-administration listing remains unchanged. Frontend must use the new picker and inspect redacted DEV evidence if only one eligible role appears. |
| 21 | FIXED BACKEND | Structured `Rule::in` accepts both number formats independently of settings phone. |
| 22 | NEEDS DEV EVIDENCE; FRONTEND | Show explicit no-employee-link state; admin is not implicitly an employee. |
| 23 | FRONTEND | Login logo asset/rendering only. |

## Corrections and V1 handling

The previous uncommitted QA patch is preserved: company website and number-format validation, task-assignee discovery, route and regression tests. This continuation adds `CustomerPhone` to shared V2 customer validation, applies safe bare-domain HTTPS normalization to CRM account create/update, and adds tests. The final role-contract correction adds a separate invitation-eligible listing while retaining the complete role-administration response and server-side assignment checks. The V1 **business rule** reused is customer phone character/digit validation; the V1 controller, broad serialization and authorization were not copied. V1's invoice/FBR shared tables, immediate provider send, plaintext token, fixed numeric roles, implicit employee identity, public files and unsafe financial arithmetic were deliberately not reused. No verified V1 company-ID-specific exception was encoded. Historical or live FBR data was not accessed or imported.

Native Accounting and dedicated FBR Invoicing remain separate. This patch does not change posting, provider transport, FBR configuration, journals, AR, payroll, attendance, leave, banking, inventory, or financial calculations. The new task lookup exposes only employee UUID, code, name and status to authorized company users; customer and CRM validation preserve tenancy and existing permissions.

## Exact changed files

Application: `app/Http/Controllers/Api/V1/Platform/CompanySettingController.php`, `app/Http/Controllers/Api/V1/Platform/InvitationController.php`, `app/Http/Controllers/Api/V1/TaskAdminController.php`, `app/Http/Requests/Api/V1/Crm/StoreCrmAccountRequest.php`, `app/Rules/CustomerPhone.php`, `app/Services/Platform/PlatformAccessService.php`, `app/Support/CustomerValidation.php`, `routes/api.php`.

Tests: `tests/Feature/Accounting/CustomerApiTest.php`, `tests/Feature/CrmAccountContactApiTest.php`, `tests/Feature/PlatformAdministrationTest.php`, `tests/Feature/PlatformInvitationEntitlementTest.php`, `tests/Feature/Stage16/EmployeeWorkTest.php`.

Documentation: `docs/qa/zeeshan-backend-api-contract.md`, `docs/qa/zeeshan-backend-remediation-report.md`, `docs/qa/zeeshan-v1-v2-backend-parity.md`, `docs/qa/zeeshan-backend-frontend-contract.md`, this report.

No migration, dependency, frontend or V1 source file changed. No generated artifacts, `.env` file or secret is included.

## Local verification

- Current affected platform/invitation/employee-work tests: **50 tests, 350 assertions, zero failures**.
- Current Stage 16 suite: **167 tests, 1,599 assertions, 20 guarded skips, zero failures**.
- Current full backend suite: **810 tests, 6,201 assertions, 32 guarded skips, zero failures**.
- Earlier explicit tenant/RBAC/FBR firewall/payroll/accounting sample: **61 tests, 612 assertions, zero failures**. These are included in the full suite; counts are not additive.
- Laravel Pint: PASS; Composer strict validation: PASS; Composer advisory audit: PASS (no advisories); PHP syntax of the newly modified executable files: PASS. The prior full-project syntax scan passed before this correction.
- Route cache creation, cached route boot/list, clear: PASS. **581 routes, zero duplicate names**; the invitation-only route has Sanctum and company-resolution middleware.
- Secret, unsafe TLS, direct posting mutation and public-file scans of changed scope: no application-code finding. A `Journal::factory()` match is an existing test fixture, not runtime journal mutation. `git diff --check`: PASS.

The preceding local tests ran against SQLite. **Accepted external MariaDB 11.8.9 evidence for the current executable candidate:** guarded preflight PASS; full regression **810 tests, 6,049 assertions, 42 guarded skips, zero failures/errors**; concurrency **107 tests, 1,776 assertions, zero failures/errors**. The user supplied these results from the disposable certification environment; Codex did not execute the MariaDB commands. The earlier 805-test/6,021-assertion certification covered a prior candidate and is superseded. No executable application or test behavior was changed during this documentation-only finalization.

## Remaining risks and decisions

DEV login/portal/role symptoms (#2, #13, #14, #20, #22) need redacted HTTP status/body, selected company/membership/permission and cookie/origin evidence. The frontend reference checkout was unavailable at the specified path in this workspace; an older zip uses mock role data and cannot certify the current frontend consumer. This report defines the API handoff, not a frontend completion claim. Full beneficiary bank information (#5), overnight shifts (#10) and an official HS catalogue (#19) need separate approval. Real FBR/provider calls were not made. No push, deployment, production/V1-production access or Hostinger action occurred.
