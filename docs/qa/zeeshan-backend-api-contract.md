# Zeeshan QA backend API handoff

Scope: V2 backend only. All paths below are under `/api/v1`, use Sanctum authentication and `X-Company-Id` where company-scoped, and return JSON. This handoff does not change the native Accounting/FBR Invoicing boundary.

## Changed contract: task assignee selection

`GET /tasks/assignees?search=<text>&per_page=<1..50>&page=<page>`

- Requires an active company membership, `tasks.assign`, and the existing payroll-module commercial entitlement enforced by `EmployeeWorkAccess`. It does **not** require `payroll.view` or `employee.links.manage`.
- Search matches an exact employee UUID, or a substring of employee code or full name. Search is limited to 100 characters. Results are limited to the selected company and exclude resigned/terminated employees, matching task-assignment eligibility. Default page size is 20; maximum is 50. `search` and `per_page` are validated. The `page` query is handled by Laravel pagination: a positive integer selects that page; malformed, zero, or negative values fall back to page 1 rather than returning `422`.
- Response: `{ "data": [{ "id": "uuid", "employee_code": "...", "full_name": "...", "status": "active" }], "meta": { "total": 1, "current_page": 1, "last_page": 1 } }`.
- `401` unauthenticated; `403` missing permission/entitlement or inaccessible company; `422` invalid validated `search` or `per_page`. No employee email, phone, payroll, bank, address, or identity-linking information is returned. An inaccessible company's employees cannot be selected by changing the header. No version or idempotency key applies to this read-only request.

## Changed contract: invitation-eligible roles

`GET /platform/roles` remains the complete company-scoped role-administration listing for users with `platform.roles.view`; it includes archived roles and is **not** an invitation picker. It returns `{ "roles": [...], "permissions": [...] }` as before.

`GET /platform/invitations/assignable-roles` is the invitation picker for an active company member with `platform.invitations.manage`. It returns `{ "roles": [{ "id": 123, "name": "Role name" }] }`, ordered by name then ID. Only unarchived roles belonging to the selected company and containing no permissions beyond the inviter's effective grants are listed. A company member with wildcard permission can list every unarchived company role. The response does not expose other companies' roles or role-permission details. `401` means unauthenticated; `403 PERMISSION_DENIED` means missing invitation permission; an inaccessible company is rejected by company middleware. No version or idempotency key applies to this read.

`POST /platform/invitations` continues to take `email` and nonempty `role_ids` (integer array). It rechecks tenant ownership, archive state and the inviter's effective grants at write time; a prior picker result does not authorize the write. An archived or foreign role returns `403 TENANT_ACCESS_DENIED`, and an over-privileged role returns `403 PRIVILEGE_ESCALATION_DENIED`. Missing/invalid fields return `422`; an already pending invitation returns `409 INVITATION_ALREADY_PENDING`. Existing invitation queueing and acceptance behavior is unchanged.
- The picker supplies `assigned_employee_id` to the existing `POST /tasks` and `POST /tasks/{task}/assign` APIs. The backend rechecks company, employee status, permissions, task version, and create idempotency at write time; a picker result is not assignment authority. Frontend must use the returned UUID, not infer identity from name or email.

## Changed contract: company settings

`GET /platform/settings` requires `platform.settings.view`; `PUT /platform/settings` requires `platform.settings.manage`, with existing `platform.settings.high-risk` for protected changes. Both are company-scoped; the existing audit record remains in place. The current company-settings response schema is unchanged.

- `number_format` accepts exactly `1,234.56` or `1.234,56`. Invalid values return `422` with `errors.number_format`. An empty or populated `phone` does not change this validation.
- `website` accepts a full HTTP/HTTPS URL, or a bare domain (optionally with path/query/fragment), which is stored and returned with `https://` prepended. Unsupported or unsafe schemes, protocol-relative URLs, and malformed values return `422` with `errors.website`. The frontend may display a scheme hint but should not construct an unsafe URL.
- `phone` remains an optional string of at most 40 characters; international formatting is not reduced to a numeric-only value. No version or idempotency key is introduced for this existing settings update.

## Changed contract: customer phone and CRM company website

- `POST /accounting/customers` and `PATCH /accounting/customers/{customer}` accept optional `phone` with 7–15 digits, common separators (`+`, spaces, `-`, `(`, `)`, `.`), and a maximum field length of 40. Letters or an invalid digit count return `422` with `errors.phone`. The formatted value is preserved; it is not converted to a number. `contact_person` remains a free-text name.
- `POST /crm/accounts/{account}/customer-handoff` uses the same customer-phone rule when creating a customer. Invalid phone input returns `422` without creating a customer or linking the account. Linking an existing customer does not revalidate that customer's historical phone value.
- `POST /crm/accounts` and `PUT /crm/accounts/{account}` accept a safe bare website domain (optionally with path/query/fragment), stored and returned as `https://...`. Full HTTP/HTTPS URLs remain valid. Unsafe schemes and protocol-relative forms return `422` with `errors.website`. This normalization does not apply to unrelated lead/contact URLs or phone fields.

## Existing contracts the frontend should use

| Concern | Existing API and authority | Frontend integration note |
|---|---|---|
| Company sign-in and selection | `POST /auth/login`, `GET /auth/me`, `POST /auth/switch-company`, `POST /platform/companies` | Use returned active memberships and company context. Do not infer access from a company name or use an unlinked employee identity. Credentialed SPA requests need the deployed trusted CORS/Sanctum configuration. |
| Employee portal identity | `GET /employee/me` plus administrative `GET /platform/employee-link-options` and explicit link management | Employee self-service requires active membership, `CompanyUser.employee_id`, `employee.self.view`, and the module entitlement. A platform administrator is not automatically an employee. |
| Dedicated FBR invoices | `GET/POST /pakistan-fbr/invoices`, `GET/PATCH /pakistan-fbr/invoices/{invoice}`, `POST /pakistan-fbr/invoices/{invoice}/submit`, `POST /pakistan-fbr/invoices/{invoice}/retry`, `GET /pakistan-fbr/invoices/{invoice}/attempts` | Submit/retry visibility must follow server-provided state and permissions. Never substitute `/accounting/*` invoices or use a native accounting ID. Historical FBR records are read-only and never submitted. |
| FBR company configuration | `GET/PUT /pakistan-fbr/configuration` | `fbr.configuration.manage` governs credential updates. Credential storage is encrypted; responses expose configuration state, never the raw token. Respect sandbox/production selection. |
| FBR reference data | `GET /pakistan-fbr/reference-data?category=HS_CODE` | The current endpoint returns all stored values matching supported filters; it does not implement search or pagination. The development seed is a fixture, not an official complete HS-code catalogue. Do not hardcode or fabricate the list. |
| Roles and invitations | `GET /platform/roles`, `GET /platform/invitations/assignable-roles`, `POST /platform/invitations` | Use the invitation-specific listing for the picker. The administration list includes archived roles; server-side assignment remains authoritative. |
| Announcements | `POST/PATCH /hrm/announcements`, publish action | Send the expiry as an ISO timestamp; backend owns publication and expiry. A calendar widget is frontend presentation. |
| Expense categories | `GET /hrm/expense-categories`, admin active-state action | `is_active` is boolean. Present Yes/No or Active/Inactive in the UI; do not change API or database types for labels. |
| Shift swap and equipment requests | Existing `/employee/shift-swaps` and HRM decision endpoints; existing employee asset-request endpoints | A swap requires target acceptance then admin decision. These endpoints do not imply inventory stock movement or custody. Overnight shifts remain unsupported. |

Company settings, FBR configuration, task assignment, and employee linking are separate authorities. No QA change creates automatic payroll, accounting, banking, inventory, or CRM effects. Frontend confirmation wording and login logo quality have no backend contract change.
