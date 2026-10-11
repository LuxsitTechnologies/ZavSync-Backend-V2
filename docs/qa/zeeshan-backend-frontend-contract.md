# Zeeshan QA: backend → frontend contract

This is the backend contract for the 23 observations in `Zavsync from WhatsApp Business.xlsx`, Sheet1 rows 2–24. The complete V1/V2 evidence and disposition is in `zeeshan-v1-v2-backend-parity.md`. No frontend code is changed here. API paths below are relative to `/api/v1`; company-scoped requests require an authenticated Sanctum session and the selected `X-Company-Id`.

| QA | Frontend contract and remaining work |
|---|---|
| 1 | Customer `phone` is optional formatted text, 7–15 digits with common separators, maximum 40 characters. Accounting customer create/update and CRM customer handoff return `422 errors.phone` for letters or bad digit count. Do not apply this rule to `contact_person` or unrelated employee fields. |
| 2 | Use `POST /auth/login`, `GET /auth/me`, active memberships and company selection. A DEV login failure requires a redacted status/body and cookie/origin evidence; do not infer access from company name. |
| 3 | Dedicated FBR draft, submit and retry are distinct actions. Show controls according to the returned invoice state and permissions; never send a native accounting invoice ID to FBR endpoints. |
| 4 | Designation and department names may contain digits. Remove numeric-character bans from relevant UI fields. |
| 5 | Do not collect or display full employee bank beneficiary details until encrypted storage, field-level authority, masking, retention and payroll-payment use are approved. Current `employee_bank_reference` is not that contract. |
| 6 | `PUT /platform/settings` and CRM account create/update accept a bare public website domain or an HTTP/HTTPS URL. The backend prefixes safe bare domains with `https://`. Show `422 errors.website` for unsafe schemes. |
| 7 | Use a calendar/date-time control for announcement `expires_at`, submit an ISO timestamp, and present the company's timezone. The backend owns publication and expiry; attachments remain private. |
| 8 | Task assignee picker: `GET /tasks/assignees?search=&per_page=&page=`. Requires `tasks.assign` and the existing employee-work entitlement. Consume its narrow `data[{id,employee_code,full_name,status}]` and `meta`; use `id` as the employee UUID. `search` and `per_page` are validated (`422`); invalid `page` falls back to page 1. No payroll or employee-link authority follows from this lookup. |
| 9 | Clarify that FBR submit transmits to the configured provider, rather than merely saving on the application server. Confirmation text is UI-owned. |
| 10 | Overnight shifts are unsupported. Do not bypass same-date validation or simulate cross-midnight shifts. A separate timezone/DST, overlap, attendance and payroll design is required. |
| 11 | Expose existing employee shift-swap and asset-request flows. Swap requires target acceptance then administrative decision; asset requests do not move inventory stock. |
| 12 | Present expense-category `is_active` as Yes/No or Active/Inactive; API value remains boolean. |
| 13 | `GET /employee/me` requires explicit employee link, membership, permission and entitlement. Never infer employee identity from email, name or role label. |
| 14 | Company portal access uses active membership and selected company; a reported DEV failure still needs a redacted response/configuration investigation. |
| 15 | Authorized FBR configuration belongs at `GET/PUT /pakistan-fbr/configuration`; raw provider credentials must never be displayed in a response. |
| 16 | The FBR Configuration UI edits company-scoped provider environment/credential state, separate from reference-data browsing and invoice submission. Do not invent another settings store. |
| 17 | Use task-assignee lookup from #8 and send the returned UUID to task create/assign. The write endpoint revalidates eligibility, company, permissions and version. |
| 18 | Accounting invoice screens use `/accounting/*`; FBR register/screens use `/pakistan-fbr/*`. The IDs, states and financial effects are intentionally incompatible. |
| 19 | `GET /pakistan-fbr/reference-data?category=HS_CODE` returns stored values, not an official full catalogue. No search/pagination contract exists for this endpoint. Do not hardcode or fabricate missing HS codes. Official source/sync remains a product-security decision. |
| 20 | Use `GET /platform/invitations/assignable-roles` for the invitation picker. It requires `platform.invitations.manage` and returns only active, company-owned roles within the inviter's effective grants as `{roles:[{id,name}]}`. `GET /platform/roles` remains the complete `platform.roles.view` administration list, including archived roles; do not use it as the picker. `POST /platform/invitations` rechecks eligibility (`403` for foreign/archived or over-privileged roles). If DEV still shows one role, inspect the redacted picker response and actor grants; do not synthesize roles in UI. |
| 21 | `PUT /platform/settings` accepts `number_format` exactly `1,234.56` or `1.234,56`, independent of the optional settings `phone`. Invalid values yield `422 errors.number_format`. |
| 22 | An admin is not automatically an employee. Show the explicit no-link state and direct an authorized administrator to employee-link management; never create an implicit link. |
| 23 | Login logo rendering/asset quality is frontend-owned; no backend API change. |

## Safety and integration notes

See `zeeshan-backend-api-contract.md` for exact changed route schemas, authority, error handling and existing FBR/roles/portal endpoint mapping. The task-assignee response is intentionally privacy-minimized. Website normalization does not license arbitrary URL schemes. FBR configuration secrets are write-only/masked. Historical FBR invoices are read-only. Dedicated FBR invoice operations do not create native Accounting invoices, journals, AR, payments, inventory or banking effects. Frontend work should distinguish backend-confirmed fixes from DEV configuration symptoms and from blocked policy decisions.
