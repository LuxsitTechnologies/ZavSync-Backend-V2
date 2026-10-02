# Stage 16C — user and employee identity foundation

## Authority and migration parity

V2 `User` is a global login account. `company_users` is the company-specific membership and role boundary. `Employee` is a company HR record; `EmployeePayrollProfile` remains the separate payroll authority. V1's `users.company_id` and `employees.user_id` represent a one-company design and must not be copied into V2. V1 automatically created an account with a fixed initial password while creating an employee, returned raw user/employee models from `/api/me`, stored employee photos under `public/employees`, and changed global user status from an employee action. Those behaviors are not ported.

| Capability | Class | Stage 16C decision |
| --- | --- | --- |
| Global account, password recovery, roles, membership, notifications, documents, audit | A — retain V2 | Existing authorities remain unchanged. |
| Company-specific employee identity, narrow account profile, password change | B — extend V2 | Add explicit membership link and purpose-specific responses. |
| V1 employee-contact and HR-profile editing | E — defer | Stage 16C self-profile is read-only for every status. |
| V1 emergency contacts and experience/education | E — defer | No new HR tables solely for parity. |
| V1 automatic account creation, email/name runtime matching, public photos, raw profile serialization | D — do not port | These violate V2 identity or privacy boundaries. |

| V1/V2 concern | Class | Authoritative Stage 16C treatment |
| --- | --- | --- |
| Authenticated account identity; name | B | Global V2 `User`; narrow account profile permits name update only. |
| Account email | A/E | Read from V2 `User`; verified email-change workflow deferred. |
| Account phone; avatar | E | No account-owned phone field or approved private avatar lifecycle. |
| Password change | B | Current-password check, confirmation, token/session revocation, security event. |
| Forgot/reset password; session security | A | Preserve existing V2 flows; change-password revocation follows them. |
| Notification preferences | A | Existing V2 preference contract, separate from HR profile. |
| Company memberships; multi-company users | A | `company_users` remains the company authority. |
| Roles; permissions | A/B | Existing RBAC retained; add self-view and link-admin permissions only. |
| Employee linkage; employee self-profile | C | Explicit membership-to-employee link and read-only, scoped `employee/me`. |
| HR administrative profile | A | Existing employee CRUD remains the HR administrative authority. |
| Payroll profile; salary/allowances/deductions | A | Existing Payroll domain; absent from self-profile. |
| Department; designation; employment dates/status | B | Read-only safe self-profile fields; HR administration owns changes. |
| HR phone/contact; address; DOB/gender | E | No employee self-edit; expose only the approved narrow existing contact fields. |
| Bank/payment; statutory/tax information | A | Existing Payroll authority; never serialized in self-profile. |
| Emergency contacts; experience/education | E | Deferred to a later HR-profile stage; no Stage 16C tables. |
| Inactive/resigned/terminated employees | B | Identity readable while membership active; no HR self-edit or global disable. |
| Invitation users | A/B | Existing membership creation; never auto-create/link employment. |
| Platform administrators | A/B | Existing admin scope; no fabricated employee identity or link bypass. |
| Audit trail | A/B | Existing audit/security-event infrastructure records link and account changes. |

## Linkage and cardinality

`company_users.employee_id` is nullable. One membership has at most one employee; unique `employee_id` means one employee has at most one membership. A composite foreign key from `(company_users.company_id, company_users.employee_id)` to `(employees.company_id, employees.id)` enforces same-company identity at the database layer. A user may have separate membership/employee links in different companies. Existing employees and newly accepted invitations remain unlinked until an administrator acts. No email or name matching occurs at runtime.

`GET /api/v1/platform/users/{membership}/employee-link`, `PUT` with `employee_id`, and `DELETE` require active company membership and `employee.links.manage`. They return only membership/link IDs and state. PUT refuses implicit reassignment; unlink first, then explicitly link. Cross-company IDs return 404. Link/unlink are serialized under the existing company row lock and audited with identifiers only. The database unique and composite foreign-key constraints remain the final authority under races.

## Account and employee response contracts

`GET /api/v1/auth/profile` returns only `id`, `name`, `email`, `email_verified`. `PATCH /api/v1/auth/profile` updates only global `name`; email and privilege fields are prohibited. No email-change lifecycle is introduced because V2 has no verified change-and-reauthentication flow. No avatar endpoint is introduced; existing private documents do not define an account-avatar replacement and retrieval lifecycle.

`POST /api/v1/auth/change-password` requires `current_password`, a confirmed new password of at least 12 characters, and the existing authenticated session/token. On success it revokes all API tokens and other database sessions (when that session driver is used), preserves the current browser session, and writes a security event without credentials. Wrong current password is rejected and recorded without storing the attempted value. The endpoint is rate-limited. Existing forgot/reset password behavior is unchanged.

`GET /api/v1/employee/me` requires `employee.self.view` in the selected active company. It returns `{linked, employee, self_editable}`. An unlinked membership returns `{false, null, false}` and never searches the employee list. A linked profile contains only employee ID/code, name, HR contact and organizational fields, employment type/status/dates, and location. It omits payroll profiles, salary, bank/payment configuration, statutory/tax identifiers, private notes, and other employees. There is no employee self-edit endpoint. Existing statuses `resigned` and `terminated` remain readable with `self_editable: false`; they do not disable the global user or unrelated company memberships. Suspending company access remains an explicit membership/security action, not an employee-status side effect. Platform-admin status alone never creates employee identity or permission.

The later frontend must use the active company context for `employee/me`, refresh it after company switching, and treat `linked: false` as an honest unlinked state. It must not search employees or infer a link by email. Global account profile and employee HR profile are separate views. No HR edit or avatar control should be shown. Link administration is available only to authorized managers; validation errors include malformed IDs, while missing/cross-company IDs and conflicting or reassigned links use the API's existing 404/409 patterns. A password change should prompt reauthentication for revoked API tokens and other sessions.

## Later V1 identity migration

Migration tooling must map V1 user ID, company ID, and employee ID through separately reviewed V2 user and company mappings to the V2 membership ID and employee UUID. Only a deterministic one-to-one candidate may be proposed for an explicit link operation. Missing users, duplicate emails, duplicate employee associations, inactive employees, and ambiguous company membership mappings become exceptions for manual review. No historical employee is linked automatically, and no V1 production data is accessed in Stage 16C.

## Certification boundary

SQLite migration and feature tests verify the local contract. The new composite foreign key, uniqueness, and company-row locking require separate MariaDB 11.8.9 certification in the guarded disposable database before publication. The existing Stage 15 MariaDB certification harness must be used with private credentials and fake external providers. This stage does not approve production cutover, frontend integration, or Employee Portal implementation.
