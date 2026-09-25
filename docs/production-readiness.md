# ZavSync production readiness

This checklist records application readiness separately from infrastructure certification. `PASS` means verified locally, `NOT EXECUTED` means the required external environment was unavailable, `BLOCKER` prevents production release, and `DEFERRED` is intentionally outside Stage 10.

| Area | Status | Evidence / production action |
| --- | --- | --- |
| Application migrations | PASS | Fresh SQLite migration and development seed are mandatory release gates. Production deployment must run `php artisan migrate --force` after a verified backup. |
| Queue foundation | PASS | Database queue, retry-safe notification/export jobs, failure recording, and administrative retry visibility are implemented. Run supervised queue workers in production. |
| Scheduler foundation | PASS | Invitation expiry, subscription evaluation, and failed-job pruning are registered. Configure one `schedule:run` invocation per minute. |
| Cache | PASS | Tenant-qualified entitlement keys and explicit invalidation are implemented. Configure a shared atomic-lock-capable production cache. |
| Private storage | PASS | Documents and exports use authorized controller downloads from private storage. Configure durable encrypted object storage and lifecycle policies. |
| Malware scanning | DEFERRED | Upload allowlists, MIME/extension validation, private storage, and authorized downloads are implemented. Integrate a production malware scanner before accepting untrusted external files at scale. |
| Email | NOT EXECUTED | Queued email delivery is implemented; production SMTP/provider credentials and delivery verification remain required. |
| HTTPS | NOT EXECUTED | Terminate TLS at the production edge, set secure session cookies, and verify proxy trust configuration. |
| Environment configuration | NOT EXECUTED | Supply production `APP_KEY`, database, cache, queue, mail, storage, FBR, and trusted-origin settings through a secret manager. Never commit them. |
| Database backups | BLOCKER | Configure encrypted automated MySQL backups with monitored retention before production launch. |
| Document backups | BLOCKER | Include the private document/export bucket in encrypted backup and retention policy. |
| Restore test | BLOCKER | Restore database and document backups into an isolated environment and reconcile record/file counts. Not executed in Stage 10. |
| Monitoring | NOT EXECUTED | Connect provider-neutral application, HTTP, queue, scheduler, and infrastructure metrics/alerts. |
| Logging | PASS | Request IDs plus user/company context are added without credentials or tokens. Configure centralized retention and alerting in production. |
| Secrets hygiene | PASS | Local repository scan is a mandatory gate; production secrets must remain external. |
| FBR production certification | BLOCKER | Production credentials, endpoint certification, and operational retry monitoring require FBR-provided infrastructure. |
| MySQL concurrency certification | BLOCKER | Not executed unless a dedicated MySQL test environment is available. See the matrix below. |
| Rollback procedure | NOT EXECUTED | Deploy forward-compatible code, back up first, stop workers during incompatible changes, and use a forward-fix migration for destructive schema changes. Rehearse before release. |

## MySQL concurrency certification matrix

Execute against the production MySQL major version with separate database connections and barriers that make operations race. Verify database rows, idempotency records, journal balance, operational/GL reconciliation, and absence of duplicate numbers after every scenario.

- Accounting: journal numbering, direct posting, reversal, and duplicate idempotency requests.
- Receivables: invoice numbering, simultaneous partial/full payments, and duplicate payment retries.
- Payables: PO/bill numbering, concurrent receipt/billing reservations, and supplier payments.
- Inventory: simultaneous FIFO sales, receipts, transfers, adjustments, and cost-layer locks.
- Banking: concurrent imports, matching, settlement references, transfers, and reconciliation completion.
- Budget/close: budget activation, period close/reopen, year-end close, and duplicate closes.
- Payroll: approval/post races, recalculation/post races, salary payments, liability settlements, and duplicate retries.
- CRM: lead conversion, customer handoff, import confirmation, and duplicate retries.

Current status: **MYSQL CONCURRENCY CERTIFICATION: NOT EXECUTED**.

## Backup and restore acceptance

Before production, record backup timestamps and encryption/retention settings, restore the database and private storage to an isolated environment, run migrations without mutation, verify tenant counts and sampled checksums, execute the financial reconciliations, and document recovery time and recovery point achieved. A backup is not accepted until this restore procedure succeeds.
