# Stage 16A — module visibility authority

## Audited baseline and decision

Backend `main` was `23017b75f70fb07ae6b5fba650c3bf198ba342ae` and the read-only frontend and V1 references were `e46f9312addf296e0f95d54c2fbde1c84b58be56` and `56932b69b73deb5f79e245be7fbbe31b2fbe017e`. Product decision: retain the shared `invoicing` commercial entitlement, but give Native Accounting Invoices and dedicated FBR Invoicing independent presentation identities. This is not an entitlement migration.

V1 `modules` has an integer ID, unique slug, name, description, active flag, soft deletion, parent, order, icon, route, core flag, and type. `module_hierarchy` stores parent/child, level and required flag. `company_module_settings` stores company/module, `visible`, `inherited`, timestamps, and a company/module unique key. V1 seeders include a flat catalog and a larger parent/child tree. V1's model treats an absent setting as active/default visible, but `my-company-modules` lists ordinary users only when an explicit visible setting exists. That endpoint does not intersect its result with permissions; super admins receive all active modules. V1's reset creates explicit visible rows. No separate per-user presentation preference was found. None of these authorization/default shortcuts are ported.

V2 `PlatformModule` and plans/subscriptions/company entitlement overrides already own commercial access. `EntitlementService::enabledModules` returns commercial keys and has a legacy fallback for companies without subscription or overrides; `auth/me` returns those keys and role permissions separately for each active membership. Existing frontend navigation filters by module then permission and rebuilds from the active company on company switch. Prior to Stage 16A, `PlatformModule.is_active` was not part of `enabledModules` and therefore was not a reliable navigation-availability signal. The new effective-navigation contract explicitly checks it without silently changing the legacy commercial `modules` field or API entitlement enforcement. Platform administrators have no implicit company membership, entitlement, or permission bypass in the new contract.

## Parity / authority matrix

Classification: A retain V2; B extend V2; C adapt V1 capability; D do not port V1 implementation; E defer product decision.

| Concern | Class | Authority / Stage 16A result |
| --- | --- | --- |
| Catalog key/name/active state | A/B | `PlatformModule`; inactive means unavailable in effective navigation. |
| Description/icon/route | D | Do not copy V1 route/component metadata; frontend owns Vue paths/icons. Stable backend item label/group/order are non-executable presentation hints. |
| Parent/child hierarchy | B/C | Static stable presentation-item parent/group metadata; no V1 `module_hierarchy` clone or inherited write propagation. |
| Plans, subscriptions, overrides, expiry | A | Existing `EntitlementService`; visibility writes never change commercial access. |
| User permissions and membership | A/B | Existing company roles and `PlatformAccessService`; explicit permission per presentation item. |
| Company visibility override | B/C | Company-scoped item key; absent row means inherited/default, visible row means explicit show, hidden row means explicit hide. Reset deletes the row. |
| Platform admin | A/D | Platform administration stays separate; no V1 show-everything shortcut inside companies. |
| Company administrator | B | Existing company-scoped `platform.settings.manage` permission required for writes; no role-name shortcut. |
| Ordinary user | B | May read effective navigation for own active company; cannot mutate settings. |
| Company switching | A/B | Re-resolve using the requested active membership and company; no cross-company navigation cache. |
| API authorization | A/D | Existing entitlement and permission checks only; presentation hiding never affects API access. |
| Native Accounting vs FBR Invoicing | B | Both require `invoicing`, but use distinct item keys and distinct permissions/overrides. |
| Per-user hiding and V1 production settings | D/E | No per-user settings or production import in Stage 16A; future mapping needs review. |

## Effective contract

For each configured presentation item: **item exists ∩ referenced PlatformModule active ∩ company commercially entitled ∩ user has the item's required company permission ∩ company has not hidden the item**. An absent override means default visible. A parent/group is organizational metadata; it cannot grant a child access. `unavailable_reason` is one of `PLATFORM_INACTIVE`, `NOT_ENTITLED`, `NOT_AUTHORIZED`, or `HIDDEN_BY_COMPANY` in that precedence. A missing catalog row is `PLATFORM_INACTIVE`. Explicit item definitions map one commercial key to one or more independent navigation keys. In particular `accounting.invoices` and `fbr.invoicing` both require `invoicing` but respectively require `accounting.view` and `pakistan_fbr.view`. The FBR configuration and migration administration items have their own existing permissions. This mapping can later reference a distinct FBR commercial key without changing presentation persistence, but Stage 16A does not make that commercial change.

`auth/me` retains the existing `modules` commercial-key array and adds `effective_navigation` per company. `GET /api/v1/platform/navigation` returns `catalog`, `items`, and `visible_keys` for the active company. Each item returns `key`, `label`, `group`, zero-based `order`, `module_key`, `required_permission`, `platform_active`, `entitled`, `authorized`, `visibility_override`, `presentation_visible`, `effective_visible`, and `unavailable_reason`. `visibility_override` is `null` when no company preference row exists (inherited/default), `true` for an explicit show row, and `false` for an explicit hide row. `presentation_visible` is the resolved presentation allowance: true for default or explicit show, false for explicit hide. `effective_visible` additionally requires an active platform module, commercial entitlement, and the user's permission. An explicit show never grants any of those authorities. The same resolver supplies administration GET, PUT, DELETE/reset, company-switch, and `auth/me` responses; PUT round-trips the explicit boolean and DELETE returns `null` after deleting the row. The company-scoped read is available to a member; `PUT /api/v1/platform/navigation/{item}` with required boolean `is_visible` and `DELETE /api/v1/platform/navigation/{item}` (reset to default) require the existing `platform.settings.manage` permission. The item key is an exact server allow-list value. Writes are serialized under the company lock and audited with only the item key and visibility state. These APIs cannot change plans, overrides, roles, permissions, or API middleware. Vue route/component names are not stored in the database. Frontend integration is not part of Stage 16A.

The currently seeded `analytics` commercial module has no separately verified frontend navigation item in the audited V2 sidebar; it remains in `catalog` but Stage 16A does not fabricate a route or permission mapping for it. Dashboard and platform administration remain outside commercial module navigation. Platform admin status alone does not supply company membership or item authorization. A presentation-hidden item may still be accessed through its normal API when its commercial entitlement and permission pass; `PlatformModule.is_active` is enforced by this new effective-navigation contract, while the legacy `modules` commercial-key field and existing API entitlement middleware remain backward compatible.

## V1 migration mapping (design only)

| V1 slug(s) | V2 commercial key / presentation item | Status |
| --- | --- | --- |
| `accounting`, `chart-of-account`, `ledger` | `accounting` / accounting items | Candidate; review old hierarchy and roles. |
| `accounting-invoices`, `invoices` | `invoicing` / `accounting.invoices` | `invoices` is ambiguous; review source context before mapping. |
| `fbr-invoices` | `invoicing` / `fbr.invoicing` | Candidate; does not imply entitlement. |
| `inventory`, `inventory-*` | `inventory` / inventory items | Candidate; per-child review required. |
| `hrm`, `employee`, `attendance`, `leaves`, `hrm-*` | No direct current V2 employee-portal mapping | Unmapped or future Stage 16B/16C; do not invent capability. |
| `payroll`, `hrm-payroll` | `payroll` / payroll items | Candidate; distinguish legacy HRM payroll. |
| `clients`, `company` | CRM/company administration candidates | Ambiguous; manual mapping required. |
| `expenses`, `document-upload-engine`, `pages`, `portal-settings` | None | Unmapped; no Stage 16A feature creation. |

Future migration must first resolve the company's V2 entitlement independently, then map only reviewed V1 visibility records to presentation-item overrides. A V1 visible row is never proof of purchase or permission. Missing V1 records map to no override (default visible *if* all other layers pass); conflicting V1 records require an exception/review. No V1 production data is accessed or imported in Stage 16A.

The Stage 16A schema has a UUID preference ID, UUID company foreign key, exact server-defined item key, boolean visibility, nullable administrative actor, timestamps, and a unique company/item constraint. MariaDB uses the existing certified `utf8mb4_nopad_bin` technical-identifier collation; SQLite keeps its binary default. The explicit `down()` drops preference rows and is therefore destructive once used; production rollback should be a reviewed forward correction after data exists.

## Security and follow-on boundary

Platform deactivation, commercial entitlement, and user permission each deny effective visibility independently. Presentation can only remove an otherwise eligible item. Hidden items do not bypass or alter API authorization. Company membership and item-key allow-list validation apply to every request. Audits contain only item key and visibility state. Stage 16B/16C may integrate this contract into the frontend and later Employee Portal navigation, but must not infer new entitlements or self-service domains from these item definitions.
