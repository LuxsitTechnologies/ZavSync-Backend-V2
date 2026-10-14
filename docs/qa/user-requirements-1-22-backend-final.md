# Requirements 1–22: backend completion register

**Uncommitted backend candidate; externally certified on MariaDB 11.8.9, but not frontend/DEV acceptance or production approval.** This register describes backend ownership. Deployed-V1 provenance and field-level evidence remain in the companion audit and parity matrix. Provider activation and future external integrations are separate from the certified backend implementation.

| ID | Backend scope and evidence | Backend status | Remaining non-backend dependency |
|---|---|---|---|
| 1 | Dedicated FBR FormRequest exposes field-specific `422` errors. | BACKEND SUPPORT COMPLETE — FRONTEND OWNED/VERIFICATION PENDING | Frontend form layout and error presentation. |
| 2 | Resource/navigation metadata uses “FBR Invoice”. | BACKEND SUPPORT COMPLETE — FRONTEND OWNED/VERIFICATION PENDING | Frontend visible copy. |
| 3 | Dedicated FBR create saves DRAFT; separate submit/retry actions distinguish Save Draft and Save to FBR. | BACKEND SUPPORT COMPLETE — FRONTEND OWNED/VERIFICATION PENDING | Frontend button wiring. |
| 4 | Stable labels and keys; Title Case is display presentation. | BACKEND SUPPORT COMPLETE — FRONTEND OWNED/VERIFICATION PENDING | Frontend heading audit. |
| 5 | Dedicated FBR routes/models never become Accounting invoices. | BACKEND COMPLETE | Frontend must use dedicated IDs/routes. |
| 6 | Sidebar expansion is transient frontend state; no backend setting needed. | BACKEND SUPPORT COMPLETE — FRONTEND OWNED/VERIFICATION PENDING | Frontend interaction test. |
| 7 | Company-scoped navigation overrides are separate from permission/entitlement authority. | BACKEND SUPPORT COMPLETE — FRONTEND OWNED/VERIFICATION PENDING | Selected-company frontend mapping. |
| 8 | Leaf `parent_key`, derived parent visibility and Accounting FBR-status identity are exposed. | BACKEND SUPPORT COMPLETE — FRONTEND OWNED/VERIFICATION PENDING | Frontend leaf/parent route map. |
| 9 | Approved organizer-private, provider-independent create/list/edit/cancel calendar scope is implemented; no external invitations were approved. | BACKEND COMPLETE | Frontend integration/acceptance remains a separate gate; provider calendar stays unavailable. |
| 10 | Idempotent pending creation; list/search/detail, versioned authorized edit, approval/activity and retained deactivation. | BACKEND SUPPORT COMPLETE — FRONTEND OWNED/VERIFICATION PENDING | Admin UI and DEV acceptance. |
| 11 | Stable HRM navigation identities; icons are frontend assets. | BACKEND SUPPORT COMPLETE — FRONTEND OWNED/VERIFICATION PENDING | Frontend icon rendering. |
| 12 | Approved integer PKR/USD catalogue, first-approval trial, managed entitlements, atomic 50,000-token monthly AI reservations, fake-backed Stripe checkout/reconciliation/webhook, payment notices, cancellation, billing history/document access, restricted payment-method portal. Separate paid component subscriptions support add-on/seat purchases and end-of-term cancellation or seat reduction. Plan changes persist pending full-price upgrades or end-of-term downgrades. Existing paid add-ons retain paid-through dates on upgrade and are not included in the new base-plan charge. Current company-scoped paid monthly seat records alone extend user/employee capacity; reductions never delete members/employees. Base-plan cancellation/deactivation also schedules all independent paid components and fails closed on unresolved billing changes. | BACKEND COMPLETE — STRIPE-READY | Live Stripe account/Price IDs/restricted portal/webhook configuration and controlled real-provider acceptance remain external activation. No real collection or live entitlement activation is claimed. |
| 13 | Versioned company dashboard layout GET/PUT/DELETE with underlying permission checks. | BACKEND SUPPORT COMPLETE — FRONTEND OWNED/VERIFICATION PENDING | Frontend widget integration. |
| 14 | Existing private company-logo contract; tagline is presentation. | BACKEND SUPPORT COMPLETE — FRONTEND OWNED/VERIFICATION PENDING | Frontend branding/hosting. |
| 15 | Verified public signup, pending-company creation, founder profile completion, first-approval trial and notification. | BACKEND SUPPORT COMPLETE — FRONTEND OWNED/VERIFICATION PENDING | Frontend registration/onboarding flow. |
| 16 | Link-preview title/tagline are static frontend/hosting metadata. | BACKEND SUPPORT COMPLETE — FRONTEND OWNED/VERIFICATION PENDING | Hosting preview test. |
| 17 | Auth membership/company capability contract exists; return navigation is frontend-owned. | BACKEND SUPPORT COMPLETE — FRONTEND OWNED/VERIFICATION PENDING | Frontend session/navigation test. |
| 18 | Company Portal reads the same Company/CompanySetting profile with versioned edit. | BACKEND SUPPORT COMPLETE — FRONTEND OWNED/VERIFICATION PENDING | Frontend profile integration. |
| 19 | Draft tombstone, pending/uncertain lock, controlled reconciliation, and the approved internal one-full-Credit-Note lifecycle with draft edit/delete, immutable snapshots and accounting firewall. | BACKEND COMPLETE — APPROVED INTERNAL SCOPE | Exact live FBR Credit Note transport and remote outcome inquiry remain external provider certification/activation. Manual evidence is not provider proof. |
| 20 | Source-verified buyer/invoice/item/tax fields, exact calculation, register filters, approved managed local HS catalogue with provenance and exact code retention, explicit **Not FBR Verified** buyer state, immutable seller/buyer/portal print snapshot, private print-data and genuine-only QR contract. | BACKEND COMPLETE — APPROVED LOCAL SCOPE | Future authoritative HS sync, buyer-registry verification, regulatory PDF/QR and live FBR provider acceptance are external integrations. Frontend must not fabricate them. |
| 21 | User-approved V2 Portal Settings contract: versioned read/update/reset, private logo, protected FBR credential and verified effect on subsequent FBR print snapshots. | BACKEND COMPLETE — APPROVED V2 CONTRACT | Deployed-V1 frontend visual parity remains unverified; this V2 replacement is not represented as observed deployed-V1 behavior. |
| 22 | Company list/create/view/edit/approve/activity, encrypted optional token, separate approval/active states, retained deactivation; no hard deletion. | BACKEND SUPPORT COMPLETE — FRONTEND OWNED/VERIFICATION PENDING | Admin/Company Portal UI and DEV acceptance. |

## Current verification state

The stabilized local SQLite PHPUnit checkpoint is **919 tests / 7,466 assertions / 34 guarded skips**, zero failures/errors. The test runner needed a process-level 512 MB PHP memory limit. Fresh temporary SQLite migration and development seed passed with the component, plan-change and seat-reduction migrations present. Pint, Composer strict validation, dependency advisory audit and route cache/boot passed; route inspection found **648 routes and zero duplicate names**. The earlier MariaDB-only `security_events.result` length defect was corrected with a forward `VARCHAR(20)` → `VARCHAR(32)` migration that refuses data-losing rollback.

Post-edit local checks before the final external run: PHP syntax across `app`, `database`, `routes`, `tests`, `config` and `bootstrap` passed; route cache/create/list/clear passed with 648 routes and no duplicate names; Stage 15–16 focused regression passed with 333 tests / 3,522 assertions / 33 guarded skips; staged and unstaged `git diff --check` passed. Scoped secret-pattern, generated-artifact, direct-journal-mutation and floating-point billing/FBR scans returned no findings. The exact changed-file boundary is **126 backend repository paths**, including **14 new migrations**, listed below.

**Accepted final external certification supplied by the user, not executed by Codex:** guarded preflight `mysql / zavsync_v2_stage15_cert / MariaDB 11.8.9` PASS; PHP 8.5.4; MariaDB full regression **919 tests / 7,314 assertions / 44 guarded skips / zero failures or errors / exit 0**; MariaDB concurrency **121 tests / 2,080 assertions / zero failures or errors / exit 0**. The current executable tree was frozen for documentation-only finalization. DEV, live Stripe, live FBR and frontend acceptance: **NOT EXECUTED**. No commit, push or deployment occurred.

Documentation-finalization executable-tree digest (SHA-256 of sorted per-file SHA-256 output for tracked and untracked `app`, `routes`, `database`, `tests`, `config`, `bootstrap`, `composer.json` and `composer.lock`): `92623f0f884b0a68ae50ea9db5ac65744702dcd5405f530646194a9d7d79c3cb`. The digest matched before and after the documentation edits. No separate certification-time digest was supplied, so this confirms that finalization did not alter executable files; it is not an independent cryptographic comparison to the external run.

## Architectural boundary

Native Accounting remains `/api/v1/accounting/*` with `Invoice`, AR, customer payments, journals and GL authority. Dedicated FBR Invoicing remains `/api/v1/pakistan-fbr/*` with `PakistanFbrInvoice`, its attempts and its internal credit notes. Neither a live FBR document nor a historical import automatically creates a native Accounting invoice, journal, AR, payment, inventory or banking effect. Internal credit-note state `RECORDED_INTERNAL` never implies provider acceptance.

## Exact changed-file inventory against `013471be8d4a407388798135b640d5bc07943b39`

The inventory below records an earlier interim candidate before the approved billing/FBR follow-up. It is **not** the final changed-file list. The current complete 126-path inventory follows in the next section. No frontend or V1 source was changed by this backend task.

```text
app/Enums/FbrSubmissionStatus.php
app/Exceptions/FbrOutcomeUncertainException.php
app/Http/Controllers/Api/V1/Ai/InternalCalendarEventController.php
app/Http/Controllers/Api/V1/AuthController.php
app/Http/Controllers/Api/V1/Fbr/FbrReferenceDataController.php
app/Http/Controllers/Api/V1/Fbr/PakistanFbrCreditNoteController.php
app/Http/Controllers/Api/V1/Fbr/PakistanFbrInvoiceController.php
app/Http/Controllers/Api/V1/Platform/CompanyAdministrationController.php
app/Http/Controllers/Api/V1/Platform/CompanyController.php
app/Http/Controllers/Api/V1/Platform/DashboardPreferenceController.php
app/Http/Controllers/Api/V1/Platform/SubscriptionController.php
app/Http/Middleware/ResolveCompany.php
app/Http/Requests/Api/V1/Fbr/SavePakistanFbrInvoiceRequest.php
app/Http/Requests/Api/V1/Fbr/StorePakistanFbrCreditNoteRequest.php
app/Http/Resources/PakistanFbrInvoiceResource.php
app/Models/Company.php
app/Models/CompanySetting.php
app/Models/InternalCalendarEvent.php
app/Models/PakistanFbrCreditNote.php
app/Models/PakistanFbrCreditNoteLine.php
app/Models/PakistanFbrInvoice.php
app/Models/User.php
app/Services/Fbr/HttpFbrGateway.php
app/Services/Fbr/PakistanFbrCreditNoteService.php
app/Services/Fbr/PakistanFbrInvoiceService.php
app/Services/Fbr/PakistanFbrSubmissionService.php
app/Services/Platform/CompanySettingsService.php
app/Services/Platform/NavigationVisibilityService.php
database/factories/InternalCalendarEventFactory.php
database/factories/PakistanFbrCreditNoteFactory.php
database/factories/PakistanFbrCreditNoteLineFactory.php
database/migrations/2026_10_13_064917_add_seller_snapshot_to_pakistan_fbr_invoices.php
database/migrations/2026_10_13_100000_add_company_profile_approval_fields.php
database/migrations/2026_10_13_105843_add_dashboard_layout_to_company_settings_table.php
database/migrations/2026_10_13_113255_make_subscription_start_nullable_for_approval.php
database/migrations/2026_10_13_113436_create_internal_calendar_events_table.php
database/migrations/2026_10_13_124358_create_pakistan_fbr_credit_notes_tables.php
database/migrations/2026_10_13_141855_widen_security_event_result_for_email_verification.php
database/seeders/DatabaseSeeder.php
docs/qa/user-requirements-1-22-backend-audit.md
docs/qa/user-requirements-1-22-backend-final.md
docs/qa/user-requirements-1-22-decisions-required.md
docs/qa/user-requirements-1-22-frontend-contract.md
docs/qa/user-requirements-19-22-v1-v2-parity.md
routes/api.php
tests/Feature/DashboardPreferenceTest.php
tests/Feature/Foundation/AuthAndTenantIsolationTest.php
tests/Feature/InternalCalendarEventTest.php
tests/Feature/MigrationIdentifierCompatibilityTest.php
tests/Feature/PlatformCompanyAdministrationTest.php
tests/Feature/PlatformInvitationEntitlementTest.php
tests/Feature/PlatformProductionHardeningTest.php
tests/Feature/PublicCompanyOnboardingTest.php
tests/Feature/Stage15/FbrConfigurationApiTest.php
tests/Feature/Stage15/FbrTransportSecurityTest.php
tests/Feature/Stage15/PakistanFbrCreditNoteTest.php
tests/Feature/Stage15/PakistanFbrDomainTest.php
tests/Feature/Stage16/NavigationVisibilityTest.php
```

## Current staged, unstaged and untracked inventory

The following is the exact `git status --short` file boundary after the Requirements 1–22 backend work. Both index and working-tree changes are preserved; this is not a commit or deployment. `??` files are new and untracked. Frontend and V1 repositories are outside this inventory.

```text
 M app/Contracts/AiUsageMeter.php
M  app/Enums/FbrSubmissionStatus.php
A  app/Exceptions/FbrOutcomeUncertainException.php
A  app/Http/Controllers/Api/V1/Ai/InternalCalendarEventController.php
M  app/Http/Controllers/Api/V1/AuthController.php
M  app/Http/Controllers/Api/V1/Fbr/FbrReferenceDataController.php
AM app/Http/Controllers/Api/V1/Fbr/PakistanFbrCreditNoteController.php
MM app/Http/Controllers/Api/V1/Fbr/PakistanFbrInvoiceController.php
 M app/Http/Controllers/Api/V1/Payroll/EmployeeController.php
AM app/Http/Controllers/Api/V1/Platform/CompanyAdministrationController.php
MM app/Http/Controllers/Api/V1/Platform/CompanyController.php
A  app/Http/Controllers/Api/V1/Platform/DashboardPreferenceController.php
MM app/Http/Controllers/Api/V1/Platform/SubscriptionController.php
MM app/Http/Middleware/ResolveCompany.php
M  app/Http/Requests/Api/V1/Fbr/SavePakistanFbrInvoiceRequest.php
AM app/Http/Requests/Api/V1/Fbr/StorePakistanFbrCreditNoteRequest.php
MM app/Http/Resources/PakistanFbrInvoiceResource.php
 M app/Models/AiProviderConfiguration.php
MM app/Models/Company.php
MM app/Models/CompanySetting.php
A  app/Models/InternalCalendarEvent.php
AM app/Models/PakistanFbrCreditNote.php
AM app/Models/PakistanFbrCreditNoteLine.php
MM app/Models/PakistanFbrInvoice.php
 M app/Models/Subscription.php
M  app/Models/User.php
 M app/Providers/AppServiceProvider.php
 M app/Services/Ai/CopilotOutreachAiAssistant.php
 M app/Services/Ai/CopilotService.php
 M app/Services/Ai/DatabaseAiUsageMeter.php
 M app/Services/Ai/KnowledgeIngestionService.php
 M app/Services/Ai/KnowledgeRetrievalService.php
 M app/Services/Ai/ManagementBriefingService.php
 M app/Services/Ai/OpenAiProvider.php
 M app/Services/AuditService.php
M  app/Services/Fbr/HttpFbrGateway.php
AM app/Services/Fbr/PakistanFbrCreditNoteService.php
M  app/Services/Fbr/PakistanFbrInvoiceService.php
MM app/Services/Fbr/PakistanFbrSubmissionService.php
M  app/Services/Platform/CompanySettingsService.php
 M app/Services/Platform/DocumentService.php
 M app/Services/Platform/EntitlementService.php
 M app/Services/Platform/InvitationService.php
MM app/Services/Platform/NavigationVisibilityService.php
 M app/Services/Platform/NotificationService.php
 M config/services.php
A  database/factories/InternalCalendarEventFactory.php
AM database/factories/PakistanFbrCreditNoteFactory.php
AM database/factories/PakistanFbrCreditNoteLineFactory.php
A  database/migrations/2026_10_13_064917_add_seller_snapshot_to_pakistan_fbr_invoices.php
A  database/migrations/2026_10_13_100000_add_company_profile_approval_fields.php
A  database/migrations/2026_10_13_105843_add_dashboard_layout_to_company_settings_table.php
A  database/migrations/2026_10_13_113255_make_subscription_start_nullable_for_approval.php
A  database/migrations/2026_10_13_113436_create_internal_calendar_events_table.php
AM database/migrations/2026_10_13_124358_create_pakistan_fbr_credit_notes_tables.php
A  database/migrations/2026_10_13_141855_widen_security_event_result_for_email_verification.php
MM database/seeders/DatabaseSeeder.php
AM docs/qa/user-requirements-1-22-backend-audit.md
AM docs/qa/user-requirements-1-22-backend-final.md
AM docs/qa/user-requirements-1-22-decisions-required.md
AM docs/qa/user-requirements-1-22-frontend-contract.md
AM docs/qa/user-requirements-19-22-v1-v2-parity.md
MM routes/api.php
 M routes/console.php
A  tests/Feature/DashboardPreferenceTest.php
MM tests/Feature/Foundation/AuthAndTenantIsolationTest.php
A  tests/Feature/InternalCalendarEventTest.php
M  tests/Feature/MigrationIdentifierCompatibilityTest.php
AM tests/Feature/PlatformCompanyAdministrationTest.php
M  tests/Feature/PlatformInvitationEntitlementTest.php
MM tests/Feature/PlatformProductionHardeningTest.php
AM tests/Feature/PublicCompanyOnboardingTest.php
 M tests/Feature/Stage13/IntelligenceOperationsTest.php
M  tests/Feature/Stage15/FbrConfigurationApiTest.php
M  tests/Feature/Stage15/FbrTransportSecurityTest.php
AM tests/Feature/Stage15/PakistanFbrCreditNoteTest.php
MM tests/Feature/Stage15/PakistanFbrDomainTest.php
M  tests/Feature/Stage16/NavigationVisibilityTest.php
 M tests/mariadb.php
?? app/Contracts/BillingGateway.php
?? app/Contracts/FbrCreditNoteGateway.php
?? app/Http/Controllers/Api/V1/Fbr/PlatformHsCodeController.php
?? app/Http/Controllers/Api/V1/Fbr/PortalSettingsController.php
?? app/Http/Controllers/Api/V1/Platform/BillingController.php
?? app/Http/Controllers/Api/V1/Platform/BillingWebhookController.php
?? app/Models/BillingCataloguePrice.php
?? app/Models/BillingCheckoutAttempt.php
?? app/Models/BillingComponentSubscription.php
?? app/Models/BillingPlanChange.php
?? app/Models/PakistanFbrCreditNoteAttempt.php
?? app/Services/Fbr/FbrInvoicePrintService.php
?? app/Services/Fbr/PakistanFbrCreditNoteSubmissionService.php
?? app/Services/Fbr/UnavailableFbrCreditNoteGateway.php
?? app/Services/Platform/BillingCancellationService.php
?? app/Services/Platform/BillingCatalogueService.php
?? app/Services/Platform/BillingCheckoutService.php
?? app/Services/Platform/BillingComponentService.php
?? app/Services/Platform/BillingNoticeService.php
?? app/Services/Platform/BillingPlanChangeService.php
?? app/Services/Platform/BillingReconciliationService.php
?? app/Services/Platform/BillingSubscriptionLifecycleService.php
?? app/Services/Platform/BillingWebhookService.php
?? app/Services/Platform/CommercialStateService.php
?? app/Services/Platform/StripeBillingGateway.php
?? app/Services/Platform/StripeWebhookVerifier.php
?? database/factories/BillingCataloguePriceFactory.php
?? database/factories/BillingCheckoutAttemptFactory.php
?? database/factories/BillingComponentSubscriptionFactory.php
?? database/factories/BillingPlanChangeFactory.php
?? database/factories/PakistanFbrCreditNoteAttemptFactory.php
?? database/migrations/2026_10_13_185808_create_pakistan_fbr_invoice_reconciliations_table.php
?? database/migrations/2026_10_13_190506_add_fbr_document_presentation_settings_and_snapshot.php
?? database/migrations/2026_10_13_191912_create_billing_catalogue_and_transactions.php
?? database/migrations/2026_10_13_214425_create_ai_usage_reservations_table.php
?? database/migrations/2026_10_13_222807_create_billing_component_subscriptions_table.php
?? database/migrations/2026_10_13_224358_create_billing_plan_changes_table.php
?? database/migrations/2026_10_13_225337_create_billing_component_quantity_changes_table.php
?? database/seeders/BillingCatalogueSeeder.php
?? tests/Feature/BillingCatalogueTest.php
?? tests/Feature/BillingComponentTest.php
?? tests/Feature/BillingPlanChangeTest.php
?? tests/Feature/BillingWebhookTest.php
?? tests/Feature/FbrPortalSettingsTest.php
?? tests/Feature/LocalHsCodeCatalogueTest.php
?? tests/Feature/MariaDbBillingConcurrencyTest.php
?? tests/Feature/Stage16/MariaDbAiQuotaConcurrencyTest.php
```
