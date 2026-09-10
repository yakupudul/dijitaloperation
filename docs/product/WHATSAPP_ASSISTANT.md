# WhatsApp Assistant — staging source implementation

User-authorized scope, 2026-09-10: one simple menu inside MoxDOP. Existing WhatsApp API messages
are listed by conversation, with a suggested Turkish sales/customer-relations reply to copy.
No message sending, automatic outreach, tasks, CRM conversion, browser extension or separate app.

## Operator experience

- Sales → WhatsApp Assistant (`/whatsapp`, `operator.whatsapp`) uses the existing TailAdmin layout.
- Active Admin + access_app permission required, including every Livewire request/action. Inbox is
  owner-only by role in this initial delivery; do not grant all team members access to private chats.
- Twenty conversations per page with name/number search; fifty messages per page, newest page first.
- Reply, wait or clarify with short explanation, conversation summary, generation time and copy control.
- New message revision or changed business terms invalidates the displayed previous draft immediately.
- Background processing runs without a browser. Inbox polls at ten seconds; polling pauses while editing
  credentials. Connection check result has an explicit refresh button. No stored secret is rehydrated.

## Connection contract

Direct Meta Cloud API is the implemented adapter. Other BSP authentication/envelopes are not supported.
The owner supplies WABA ID, Phone Number ID, numeric international business number, access token,
Meta App Secret and a self-chosen Verify Token (minimum sixteen characters) in the page settings.
Credentials use existing core_integration_credentials encrypted provider payload; blanks preserve.
A distinct core_integrations provider `whatsapp` does not replace the existing `meta` integration.
The binding is fixed after configuration to prevent old receipts being interpreted as a new account.
Existing generic integration cards are not extended; configuration is in the WhatsApp menu.

Meta Callback URL: `{APP_URL}/api/whatsapp/webhook`. Configure the matching Verify Token in Meta.
Subscribe to `messages`; if Coexistence is available, also `smb_message_echoes` and `history`.
Meta app/WABA subscription and business number onboarding must already be completed externally.
This application does not register a number, perform Embedded Signup, subscribe a WABA, request
history via a write endpoint, or automate a Business-app-to-API migration.

GET verifies subscribe/challenge; POST verifies X-Hub-Signature-256 with App Secret over original bytes.
Only matching WABA ID and Phone Number ID are processed. Four MiB application payload cap and rate
limits apply; reverse-proxy body limits may be smaller and require host configuration for large history.
A queued read-only Graph GET verifies phone ID/token/display number, not webhook/history completeness.
Graph version defaults to v23.0 and is configurable with WHATSAPP_GRAPH_VERSION. Check the supported
version and real app authorization during staging acceptance. No HTTP redirects or configurable hosts.

## History and data truth

Accepts Meta envelope `entry[].changes[].value`:
- messages: text, button/interactive text and explicit placeholders for unread media/unsupported types;
- smb_message_echoes: business-app outgoing messages, validating sender number;
- history: history[].threads[].messages[] with direction checked against business/contact number.

Provider delivery/status notifications are not used as proof of delivery or reading. Contact state sync,
message edits/deletions/reactions, media download/OCR/transcription, and BSP-specific envelopes are not
implemented. Quoted message IDs are retained; quoted content outside the supplied AI window is unknown.
Names come from contacts attached to message events; history-only threads may initially show a number.
History availability depends on the provider/onboarding scope; `received_partial` never means complete.
Unsupported/bad-scope counts and failed receipts are visible; failed receipts can be retried manually.
Existing phone history cannot be fetched merely by entering an access token.

## Persistence and async

Three additive tables: whatsapp_webhook_receipts, whatsapp_conversations, whatsapp_messages.
Receipt payload, message body and AI reply/summary/rationale are encrypted using the existing APP_KEY.
Contact names/numbers and routing/timestamp metadata are operational plaintext. Preserve APP_KEY backups.
Successful receipts discard encrypted raw payload but retain their exact hash to deduplicate redelivery.
Messages deduplicate by conversation + provider message ID, never by text or timestamp.
No cross-conversation context retrieval. Existing histories are never merged by name.

The existing minute scheduler runs moxdop:whatsapp:dispatch. It admits twenty receipt jobs and up to ten
conversation jobs per tick onto existing Redis default/Horizon. Pending receipts defer generation so
history chunks are processed first. ShouldBeUnique prevents duplicate queued work, DB transactions and
conversation revisions prevent stale model output from replacing newer context. Per-request max-201-row
read bounds AI context to newest 200 messages / 60,000 characters. UI explicitly shows truncation.
Receipts have bounded retries; AI failure is explicit and manually retryable, not an unlimited cost loop.
Interrupted generation is marked failed after ten minutes. Generation/API-check jobs carry IDs, not tokens.
No worker dependency or second scheduler is added. Own durable statuses are visible on this screen;
full AgentExecutionRun/SkillExecutionRun and global Activity aggregation are not implemented in this slice.

## AI contract

Dedicated central AI route `sales.whatsapp_reply` registered through SalesServiceProvider; uses existing
AiRouteResolver, AiProviderRuntimeConfig, provider eligibility/failover and laravel/ai structured output.
OpenAI store=false. Other configured AI providers are selectable via existing AI Control Plane.
Agent reads only application-built conversation context and business terms; no model database access,
web search, tools, secrets, other conversations or implicit import of ChatGPT conversation memory.
Current Turkish date/time is supplied. User terms do not imply VAT, hosting/domain or delivery promises.
Reply=wait produces no copyable message. Validate action and output sizes before storing.
Suggestions are advisory; no guarantee of persuasive effectiveness or factual correctness is claimed.

## Deployment and verification truth

Use the existing exact-commit checkout and `bash deploy/staging/deploy.sh` from `/var/www/moxdop`.
The script installs additive migrations, builds the existing UI and restarts existing Horizon/scheduler.
Then enter WhatsApp settings and configure the Meta webhook. Existing AI integration must be available.
No main changes, PR, server deployment, dependency installation, tests, formatter, PHP/Blade compilation
or browser UAT were run. Source-reviewed staging implementation; NOT live-verified or DONE.
Real Meta signature/verification, normal and history payloads, outgoing echoes, redelivery, PostgreSQL
migration, worker recovery, access controls, UI and actual model reply quality still need staging UAT.


## WhatsApp credential form correction — 2026-09-10

Failed settings saves previously cleared all three password inputs via finally/dehydrate and showed
an unnamed first-missing error. Password inputs now stay browser-local (wire:ignore, DOM refs), are
submitted only as action arguments, and clear only on explicit successful save. Validation failures
retain unsaved inputs in the current open form, not in server snapshots or persistent browser storage.
Field-specific messages list every missing credential; server-derived presence flags distinguish
stored credentials from blank edits. Reads use a fresh provider-credential relation query. Existing
stored values remain write-only and blank submissions preserve them. Stale save errors reset before
new save attempts. No credential/account data migration or provider mutation is performed.
Source-reviewed fix only: no tests, build, formatter or live deployment run. Supersedes earlier
notes about clearing fields after failed saves. Number mismatch remains a separate configuration issue.
