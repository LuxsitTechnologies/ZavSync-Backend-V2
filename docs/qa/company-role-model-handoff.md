# Company role model handoff (uncommitted candidate)

This document describes the local role-model correction. It is not deployed or certified in DEV.

## Authority boundaries

- `user.is_platform_admin` identifies a ZavSync Admin, and `platform_user_permission` records the explicit global grants. Both the flag and the relevant grant are required. A company role or a `platform.*` tenant permission never substitutes for the flag. Global company administration and HS catalogue routes retain that boundary.
- `Admin` is the protected, company-scoped system role. Company creation assigns it to the creator. It receives the approved company permission catalogue, including `billing.view`, `billing.manage`, `portal.settings.view`, and `portal.settings.manage`. Global company, jobs, plans, and HS administration permissions and internal migration tooling are excluded. Commercial entitlements and company state still apply.
- Custom roles are company-scoped and retain their chosen permissions. Provisioning touches only system `Admin`, `Finance Administrator`, and `Company Administrator` roles. A custom role already named `Admin` causes migration to stop for manual resolution rather than being overwritten.
- Company ownership remains the `companies.created_by` relationship, separate from the `Admin` role. Existing billing and Portal Settings owner-or-permission rules remain in force.
- Employee Portal access is an independent employee relationship, not a role name. It requires an actual company membership employee link, `employee.self.view`, and payroll entitlement. A former employee remains linked and the existing self-service endpoints determine read-only behavior.

## `/api/v1/auth/me` and switch-company response

The `user.is_platform_admin` boolean remains the global authority signal. `user.platform_permissions` lists the separately granted global capabilities only for a ZavSync Admin; these do not belong to a company role. Global permissions are excluded from `companies[].permissions` and cannot be assigned through Roles & Permissions. Each company now includes:

| Field | Meaning |
| --- | --- |
| `roles`, `permissions`, `modules`, `effective_navigation` | Existing company-scoped authority and presentation data |
| `is_company_owner` | The authenticated user is `companies.created_by` |
| `employee_linked` | The current membership has an actual employee record link |
| `employee_portal_available` | Linked, has self-view permission, and payroll is entitled |

The frontend must use these fields and the existing permission/module checks. It must not infer platform authority, Admin rights, or employee linkage from a display name or another permission.

## Migration and verification

The first data migration copies active legacy global grants from company roles into `platform_user_permission` for users already flagged as platform administrators. The next migration renames an existing system role in place where possible, preserving its ID and primary/pivot membership assignments. Re-running the provisioner is idempotent. If duplicate legacy system roles coexist, assignments and invitation role IDs move to one `Admin` role. Custom roles are not synchronized. Roles & Permissions counts both primary and additional role assignments.

Before DEV acceptance, deploy and migrate this reviewed backend candidate, then verify the real `/auth/me` response for each DEV actor. Do not create acceptance actors against the obsolete `Finance Administrator` model. This local candidate has not been deployed, and no DEV actor setup has been performed yet.
