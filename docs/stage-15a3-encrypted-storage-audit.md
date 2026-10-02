# Stage 15A.3 — encrypted storage compatibility

## Root cause

Baseline: d5107ae906ab0dc85e6c6a2214d7f5e6ddcb5cbd.
The user independently reported all migrations PASS on MariaDB 11.8.9-MariaDB-ubu2404,
followed by seed error 4025 on email_provider_connections.configuration.

EmailProviderConnection casts configuration as encrypted:array. Laravel first JSON
serializes the array, then encrypts it into an opaque base64 envelope. The stored
ciphertext is not a JSON document. MariaDB's JSON validation therefore correctly
rejects it. SQLite storage did not expose this constraint. The regression test
proves ciphertext is non-JSON and the schema/cast guard fails before correction.
The credentials field already uses TEXT and was not the schema defect.

## Correction and existing-data decision

Change only configuration from nullable JSON to nullable LONGTEXT in
2026_09_25_100139_create_email_provider_connections_table.php. Preserve the cast,
nullability, hidden attributes, API resource, audits and seeder unchanged.
LONGTEXT accommodates encryption overhead without imposing JSON validation.
No encryption removal, fake JSON wrapping, strict-mode change or constraint bypass.

The repository migration guidance treats deployed migrations as immutable, but
permits correcting unshared/local definitions. The user explicitly confirms these
are pre-production V2 migrations, not the deployed V2 production database, and the
certification rerun is migrate:fresh. Thus the original definition is corrected,
consistent with the immediately preceding portability correction. No additive
migration or live data conversion is needed for that fresh-install scope.
Existing migrated local databases retain their old declaration: do not run a fresh
migration on valuable data. Any data-preserving existing-database rollout needs a
separately reviewed additive conversion and backup, retaining the encryption key
and ciphertext. Nothing here alters V1 or its data.

## Complete encrypted-field audit

All listed fields persist ciphertext when non-null. The existing plaintext JSON
columns outside this list have ordinary array casts, not encrypted casts. A source
scan additionally found encrypted invitation/reset tokens in queued payloads, not
encrypted model attributes in native JSON columns. No other identical mismatch was
identified. TEXT/LONGTEXT compatibility below is a static storage assessment, not a
MariaDB runtime result. Application size limits still govern payload capacity.

| Model | Table | Column | Migration | Original type | Cast | Ciphertext | Compatibility / action |
| --- | --- | --- | --- | --- | --- | --- | --- |
| AiActionProposal | ai_action_proposals | payload | 2026_09_25_175449_create_ai_action_proposals_table.php | longText | encrypted:array | Yes | Appropriate; unchanged |
| AiActionProposal | ai_action_proposals | impact_preview | 2026_09_25_175449_create_ai_action_proposals_table.php | longText | encrypted:array | Yes | Appropriate; unchanged |
| AiEvaluationCase | ai_evaluation_cases | prompt | 2026_09_25_175452_create_ai_evaluation_cases_table.php | longText | encrypted | Yes | Appropriate; unchanged |
| AiEvaluationRun | ai_evaluation_runs | answer | 2026_09_25_175453_create_ai_evaluation_runs_table.php | longText | encrypted | Yes | Appropriate; unchanged |
| AiMessage | ai_messages | content | 2026_09_25_175444_create_ai_messages_table.php | longText | encrypted | Yes | Appropriate; unchanged |
| AiMessageCitation | ai_message_citations | excerpt | 2026_09_25_175445_create_ai_message_citations_table.php | text | encrypted | Yes | Appropriate; unchanged |
| AiProviderConfiguration | ai_provider_configurations | api_key | 2026_09_25_175439_create_ai_provider_configurations_table.php | text | encrypted | Yes | Appropriate; unchanged |
| AiProviderConfiguration | ai_provider_configurations | settings | 2026_09_25_175439_create_ai_provider_configurations_table.php | text | encrypted:array | Yes | Appropriate; unchanged |
| AiToolRun | ai_tool_runs | input | 2026_09_25_175448_create_ai_tool_runs_table.php | longText | encrypted:array | Yes | Appropriate; unchanged |
| AiToolRun | ai_tool_runs | output | 2026_09_25_175448_create_ai_tool_runs_table.php | longText | encrypted:array | Yes | Appropriate; unchanged |
| CalendarEvent | calendar_events | attendees | 2026_09_26_034932_create_calendar_events_table.php | text | encrypted:array | Yes | Appropriate; unchanged |
| CalendarEvent | calendar_events | sync_metadata | 2026_09_26_034932_create_calendar_events_table.php | text | encrypted:array | Yes | Appropriate; unchanged |
| CalendarProviderConnection | calendar_provider_connections | access_token | 2026_09_26_034931_create_calendar_provider_connections_table.php | text | encrypted | Yes | Appropriate; unchanged |
| CalendarProviderConnection | calendar_provider_connections | refresh_token | 2026_09_26_034931_create_calendar_provider_connections_table.php | text | encrypted | Yes | Appropriate; unchanged |
| CalendarProviderConnection | calendar_provider_connections | sync_metadata | 2026_09_26_034931_create_calendar_provider_connections_table.php | text | encrypted:array | Yes | Appropriate; unchanged |
| EmailProviderConnection | email_provider_connections | configuration | 2026_09_25_100139_create_email_provider_connections_table.php | json | encrypted:array | Yes | INCOMPATIBLE → LONGTEXT; corrected |
| EmailProviderConnection | email_provider_connections | credentials | 2026_09_25_100139_create_email_provider_connections_table.php | text | encrypted:array | Yes | Appropriate; unchanged |
| EmailProviderConnection | email_provider_connections | access_token | 2026_09_25_100139_create_email_provider_connections_table.php | text | encrypted | Yes | Appropriate; unchanged |
| EmailProviderConnection | email_provider_connections | refresh_token | 2026_09_25_100139_create_email_provider_connections_table.php | text | encrypted | Yes | Appropriate; unchanged |
| EmailProviderConnection | email_provider_connections | webhook_secret | 2026_09_25_100139_create_email_provider_connections_table.php | text | encrypted | Yes | Appropriate; unchanged |
| FbrCompanyConfiguration | fbr_company_configurations | credential | 2026_10_01_145329_create_stage15a_legacy_invoice_fbr_foundation.php | text | encrypted | Yes | Appropriate; unchanged |
| IntelligenceBriefing | intelligence_briefings | structured_data | 2026_09_26_034929_create_intelligence_briefings_table.php | longText | encrypted:array | Yes | Appropriate; unchanged |
| IntelligenceBriefing | intelligence_briefings | narrative | 2026_09_26_034929_create_intelligence_briefings_table.php | longText | encrypted | Yes | Appropriate; unchanged |
| IntelligenceScenario | intelligence_scenarios | assumptions | 2026_09_26_034928_create_intelligence_scenarios_table.php | longText | encrypted:array | Yes | Appropriate; unchanged |
| IntelligenceScenario | intelligence_scenarios | baseline | 2026_09_26_034928_create_intelligence_scenarios_table.php | longText | encrypted:array | Yes | Appropriate; unchanged |
| IntelligenceScenario | intelligence_scenarios | scenario | 2026_09_26_034928_create_intelligence_scenarios_table.php | longText | encrypted:array | Yes | Appropriate; unchanged |
| IntelligenceScenario | intelligence_scenarios | delta | 2026_09_26_034928_create_intelligence_scenarios_table.php | longText | encrypted:array | Yes | Appropriate; unchanged |
| KnowledgeChunk | knowledge_chunks | content | 2026_09_25_175441_create_knowledge_chunks_table.php | longText | encrypted | Yes | Appropriate; unchanged |
| KnowledgeChunk | knowledge_chunks | embedding | 2026_09_25_175441_create_knowledge_chunks_table.php | longText | encrypted:array | Yes | Appropriate; unchanged |
| KnowledgeSource | knowledge_sources | content | 2026_09_25_175440_create_knowledge_sources_table.php | longText | encrypted | Yes | Appropriate; unchanged |

## Verification scope

The automated guard discovers model casts and migration column declarations,
including future encrypted fields, without connecting to MariaDB. Focused tests
exercise factory and development-seed persistence, decrypted round-trip,
non-JSON ciphertext, absence of plaintext in storage, null handling, hidden model
serialization, API responses, audit rows and captured application logs. Existing
outreach, AI, intelligence and FBR security coverage remains intact.

No Docker, local MariaDB, production, or real FBR access is performed by this task.
Native Accounting and FBR Invoicing remain separate and unchanged.

## Executed local gates

All database-backed automated checks below used SQLite, not MariaDB.
Verification ran sequentially; route caching ran only after PHPUnit exited.

| Gate | Result |
| --- | --- |
| optimize:clear | PASS |
| Fresh migrations on temporary SQLite database | PASS; all 80 migrations |
| Development seed on that database | PASS |
| Affected encrypted-storage/outreach tests | PASS; 18 tests / 94 assertions |
| Stage 15 focused suite | PASS; 66 tests / 466 assertions |
| Complete backend regression | PASS; 511 tests / 3,076 assertions |
| Laravel Pint, dirty files | PASS |
| PHP syntax | PASS; 827 files |
| Composer strict validation | PASS |
| Composer security audit | PASS; no vulnerability advisories; retried with network access after sandbox DNS failure |
| Migration identifier compatibility guard | PASS; 1 test / 2 assertions; static MySQL grammar, no server connection |
| Routes in testing environment | PASS; 398 routes; zero duplicate names |
| Route cache create, boot, clear | PASS; semantic route parity after excluding generated names/source locations |
| Secret-pattern scan and changed-file security review | PASS; no detected secrets; fixture values only |
| Changed-file TODO/FIXME, unsafe TLS, posting-reference scan | PASS; no matches |
| Diff/whitespace and artifact review | PASS; only migration, test, and this report changed |

Test groups overlap and their counts must not be added to the full regression.
Secret scanning is pattern-based plus manual diff review, not proof that arbitrary
secrets are absent. No application casts, services, routes, or seed logic changed.

## Manual certification rerun

Use only the existing disposable certification connection. Before either command,
verify effective Laravel connection=mysql, host=127.0.0.1, port=33079,
database=zavsync_v2_stage15_cert and server=MariaDB 11.8.9. Abort on any mismatch.
Clear stale configuration only within that controlled manual workflow; supply
local credentials privately through temporary environment variables, not Git.
Then run sequentially with those same verified overrides:

```sh
php artisan migrate:fresh --no-interaction
php artisan db:seed --no-interaction
```

Do not run seed after a failed migration. Preserve output and proceed to MariaDB
tests only after both succeed. Do not target any valuable database or production.
MariaDB migrations PASS refers to the user's manual baseline run, not execution
by Codex; the corrected migration and seed still require the manual rerun.
