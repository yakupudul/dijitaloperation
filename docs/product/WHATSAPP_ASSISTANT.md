# WhatsApp Assistant — staging source implementation

> 2026-12-01 update: restored after the v2 reset. Sidebar entry **WhatsApp** (Admin only). Conversations link to
> customers only (prospects are gone). Reply suggestions use the OpenAI API only, with the model chosen on the
> WhatsApp screen; the route key is `whatsapp.reply`. Retention days are set on the WhatsApp screen.

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
  credentials, except while an API check is pending (five-second result refresh). Connection check
results also have an explicit refresh button. No stored secret is rehydrated.

## Connection contract

Direct Meta Cloud API is the implemented adapter. Other BSP authentication/envelopes are not supported.
The owner supplies WABA ID, Phone Number ID, numeric international business number, access token,
Meta App Secret and a self-chosen Verify Token (minimum sixteen characters) in the page settings.
Credentials use existing core_integration_credentials encrypted provider payload; blanks preserve.
A distinct core_integrations provider `whatsapp` does not replace the existing `meta` integration.
The binding can be corrected during empty initial setup. Once conversations or non-completed receipts
exist, rebinding is blocked to prevent old receipts being interpreted as a new account.
Existing generic integration cards are not extended; configuration is in the WhatsApp menu.

Meta Callback URL: `{APP_URL}/api/whatsapp/webhook`. Configure the matching Verify Token in Meta.
Subscribe to `messages`; if Coexistence is available, also `smb_message_echoes` and `history`.
As of the owner-authorized 2026-09-14 extension, Embedded Signup and WABA app subscription are
implemented. Meta application callback/Verify Token/field configuration still happens in Meta.
No number registration endpoint or automated Business-app migration is called. The history request
(`POST {phone}/smb_app_data`, `sync_type=history`) is sent only when the operator clicks "Geçmiş mesajları al"
inside Meta's 24-hour window after a coexistence onboarding (2026-10-05).
The default flow requests Coexistence; Meta eligibility and operator consent remain required.

GET verifies subscribe/challenge; POST verifies X-Hub-Signature-256 with App Secret over original bytes.
Only matching WABA ID and Phone Number ID are processed. Four MiB application payload cap and rate
limits apply; reverse-proxy body limits may be smaller and require host configuration for large history.
A queued read-only check verifies phone, WABA membership, token/app identity when App ID is configured,
and WABA app subscription separately. This does not prove message delivery or history completeness.
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



## WhatsApp setup and action feedback correction — 2026-09-10

Initial WABA/phone binding corrections are now allowed only with no conversations and no
non-completed receipts. Completed ignored receipts are retained. IDs compare as trimmed strings;
existing history continues to block rebinding with field-specific explanations. Receipt signature
validation/persistence and settings changes serialize on the integration row. No data is deleted.
The form distinguishes stored IDs, stored secret presence and unsaved secret edits, offers visibility
for newly typed secrets, and shows saving/success/failure feedback beside the actions. Failed saves
preserve input; controls are disabled during save. API checks use persisted queued/error/result
states, timestamps, duplicate-click suppression and automatic result refresh. Request IDs prevent
an older result overwriting settings saved during the HTTP call. Two-minute queue delays show a
retry/help message. Separate WhatsApp App Secret guidance leaves Meta Ads credentials untouched.
Added PHPUnit coverage for initial correction, receipt/history guards, blank credential preservation
and stale API results. Execution was attempted but unavailable: this workspace has no PHP executable
or installed vendor/Pint. No PHP/Blade compilation, full browser UAT, Meta verification or server deploy
was performed. The actual form submit handler passed isolated JavaScript checks for success,
validation rejection and network failure; this is not browser UAT. Source reviewed; runtime
acceptance remains pending, not DONE.



## Embedded Signup and actionable diagnostics — 2026-09-14

Owner approved the five next actions in the WhatsApp status review. Work remains on
`chatgpt/search-demand-foundation`, no main changes or PR. A scoped setup exception permits
`POST /{WABA_ID}/subscribed_apps` after explicit connection/subscription action. No message send,
number registration, automatic migration, campaign mutation or automatic history-sync request.

- `/whatsapp` now includes App ID, Configuration ID (initial form value `1757572378897162`),
  Coexistence/Cloud API choice and write-only application credentials. Stored blank secrets preserve.
  App ID must be supplied from the same Meta application's Basic settings; no ID is inferred.
- `/whatsapp/connect/{attempt}` loads the Facebook SDK only on the isolated connection page. A
  synchronous user click opens the popup. Exact Facebook origin allowlist and expected completion
  event filtering; Coexistence requires its own completion event. Code and session events may arrive
  in either order. No codes/tokens in query strings, localStorage, logs or Livewire properties.
- An additive `whatsapp_signup_attempts` table stores encrypted short-lived code/token payloads,
  initiating Admin/session hash, settings revision, expiry, phases and safe outcome details.
  CSRF, active-Admin authorization, ownership, session binding, expiry and single-consumption gates
  apply to callback and number selection. Validation failures never flash OAuth codes to session.
- Background job exchanges the code, validates the issued token's app/required scopes, reads the
  explicit WABA phone list, validates the selected phone and preserves existing conversation guards.
  If Meta omits Phone Number ID, the operator chooses an explicitly listed number, including when
  only one is present. Read is bounded to 100 phones; no arbitrary first account/number fallback.
- Commit validated credentials/binding before subscribing so webhook ingestion can route events.
  WABA subscription uses the issued token and is then confirmed with GET subscribed_apps for App ID.
  This does not configure application callback/field subscriptions. Partial subscription failure
  retains valid binding and provides a dedicated subscription retry without reusing the OAuth code.
- Existing Redis default queue/Horizon and minute WhatsApp dispatch recover undispatched durable
  jobs. Prepared/selection/queued attempts expire after 15 minutes; interrupted workers are marked
  after 3 minutes. Encrypted temporary payloads are cleared on terminal/expiry/interruption states.
  Browser closure after handoff does not stop the work. No dependency or second scheduler added.
- API check stores redacted provider message, HTTP status, Meta code/subcode and trace ID. Raw
  HTTP exceptions and secret-bearing URLs never become operator errors. WABA subscription and
  actual stored message observation are separate facts. Completed zero-message receipts are explicit.
- Callback verification records its timestamp; existing incoming/history/echo ingestion remains.
  Existing messages can appear immediately after setup, but historical availability/completeness
  and outbound echoes are not claimed before actual receipts. AI remains advisory/copy-only.

Verification: source review only. Per operator preference no tests or browser UAT were run; this
workspace lacks PHP/vendor, so PHP/Blade compilation and Pint were unavailable. No live Meta login,
API request, subscription mutation or deployment was executed from this workspace. Official Meta
implementation/Coexistence docs were requested but returned HTTP 429; the configured app's actual
flow must be accepted in staging. SDK launch uses config_id/code response and Coexistence featureType,
without forcing legacy sessionInfoVersion overrides. Live acceptance remains pending, not DONE.

Deployment: existing exact-commit staging deploy runs the additive migration and restarts workers.
In WhatsApp settings save the real Meta App ID, keep the supplied Configuration ID, confirm same-app
App Secret/Verify Token, then use WhatsApp hesabını bağla. In Meta set the displayed callback URL,
matching Verify Token and messages field (history/smb_message_echoes for eligible Coexistence).
Confirm one inbound message and an AI suggestion manually. No historical completeness claim.


## 2026-09-14 — WhatsApp signup table mapping hotfix

Operator reported HTTP 500 on /whatsapp after the Embedded Signup release. Source inspection
found WhatsAppSignupAttempt lacked an explicit table name: Eloquent infers
whats_app_signup_attempts, while the migration creates whatsapp_signup_attempts.
Set the model table explicitly, consistent with the existing WhatsApp conversation/message/receipt
models. This corrects the same query path in inbox rendering, setup guards and background signup
processing. No schema changes or data deletion. Source reviewed; runtime environment and server
logs are unavailable. No tests, formatter, live rendering or deployment performed. Operator
deployment/confirmation of page recovery remains required; Meta connection readiness is unchanged.


## 2026-09-14 — Isolate Embedded Signup from FedCM login

Operator supplied a failing Facebook URL with scope=openid, response_type=token and
dialog_source=fedcm, followed by a second signup popup. That URL is a separate SDK login
step; the application requests config_id with response_type=code for Embedded Signup.
The connection page now explicitly sets FB.init fedCM:false, status:false and xfbml:false
to opt out of FedCM and automatic status/widget processing. Login remains a synchronous
operator click with the configured business flow and Coexistence featureType.

Meta's FedCM documentation search excerpts expose the fedCM boolean/object option:
https://developers.facebook.com/documentation/facebook-login/web/fedcm
Full documentation and SDK source could not be retrieved in this workspace. The effect of
the opt-out on the current served SDK is not runtime-verified; the operator must confirm
the unexpected OpenID popup disappears after deployment. This is a targeted mitigation,
not a claim that WhatsApp onboarding or the existing-number connection is complete.
Previously reported advanced-access error #2655111 is a separate Meta approval blocker.
No extra permission, account deletion, number registration or backend mutation was added.
No tests, formatter, browser UAT or deployment performed, per operator workflow.


## 2026-12-02 — Connection hardening and screen redesign

Symptom: the operator picked a business portfolio in the Meta popup and continued; nothing came back, the attempt
stayed `prepared` and the screen showed only a raw English token error (code 190 / 463) from an old manual token.

- The popup is launched with `extras: { setup: {}, sessionInfoVersion: '3', featureType: 'whatsapp_business_app_onboarding' }`
  (coexistence). Meta requires session logging for WhatsApp Business app onboarding; a v4 configuration ignores the
  override harmlessly. Embedded Signup v2/v3 is retired by Meta on 2026-10-15, so the configuration id must be a v4
  "WhatsApp Embedded Signup" configuration.
- Session events: `FINISH`, `FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING`, `FINISH_ONLY_WABA` complete; `CANCEL`
  (`current_step`, or `error_message` / `error_id` / `session_id`) and a popup closed without a code are posted to
  `operator.whatsapp.report` and stored on the attempt (`cancelled`, details shown on the screen). A popup that
  answers within 3 seconds is reported as `LOGIN_REFUSED` (blocked popup or domain not in Facebook Login for
  Business "Allowed Domains" / "Valid OAuth redirect URIs"); an account event with no code within 45 seconds as
  `NO_CODE`. Messages are accepted only from `https://facebook.com` and its subdomains.
- Meta's exchangeable code lives about 30 seconds: `WhatsAppSignup::submit()` exchanges it inside the request
  (`exchanging`), then queues `CompleteWhatsAppSignup`. When no account event arrives within 6 seconds the page posts
  `CODE_ONLY` (with `finish_event` when Meta reported how it finished but without an account id); the job reads the
  shared WABA ids from `debug_token` granular scopes (at most 20). A single phone is picked automatically only when
  Meta reported the account or the token covers exactly one account; otherwise the operator chooses (`choose_phone`).
- `coexistence_state`: `signup_reported` (business app event, or the number reports `is_on_biz_app`), `not_used`
  (Cloud API finish in coexistence mode), `unknown` (code only), `not_requested` (Cloud API mode). For every path
  except `signup_reported` the job reads `GET {phone}?fields=platform_type,is_on_biz_app`; a number with
  `platform_type=NOT_APPLICABLE` (never registered on Cloud API) is refused before binding, since MoxDOP does not
  register numbers and no message would arrive. An unanswered check does not block.
- History: for `signup_reported` / `unknown` numbers connected through the popup, the screen shows "Geçmiş mesajları
  al" until 24 hours after `signup_completed_at`. The click sends `smb_app_data` `history` only (contacts sync is
  not used by MoxDOP); the result is `history_sync` (`requested` / `failed` + `history_sync_error`) and can be
  retried inside the window. A manual binding to another number clears this state (`connected_via=manual`).
- A reconnect or subscription attempt started from a connected number is shown above the inbox (status, Meta reason,
  continue / choose-number link) for 24 hours.
- The Meta app owner's portfolio cannot be chosen in Embedded Signup; the screen points that case to the manual form
  (WABA id, phone number id, system user token).
- Screen: setup steps when not connected (`WhatsAppConnection::state()`), two-pane inbox when connected, settings
  panel with reply-suggestion settings separated from the manual connection form. Meta errors are explained in
  Turkish by `WhatsAppErrorText`.
- Tests: `WhatsAppSignupFlowTest`. Not verified against a live Meta popup.


## 2026-12-03 — Connect-page diary and reset

Symptom: after the 2026-12-02 deploy the attempt again stayed `prepared` with no reason; the screen could not tell
"popup never opened" from "popup open, no answer" or "page left mid-way". The operator asked to start over.

- Diary: the connect page posts along-the-way entries to `operator.whatsapp.report` (`LAUNCHED`, `SDK_CALLBACK` with
  the SDK status and whether a code came — never the code, `MESSAGE` with Meta's event / step / ids, `POST_FAILED`
  with the HTTP status, `POPUP_BLOCKED`, and `PAGE_LEFT` as a `sendBeacon` form post on `pagehide`). Entries are kept
  newest-last in `whatsapp_signup_attempts.trace` (at most 40) and listed on the screen under "Bağlantı sayfasının
  kaydı". `CODE_RECEIVED` is added when `submit()` accepts the code.
- `LAUNCHED` sets `launched_at`, `step=popup_open`, returns a `cancelled` attempt to `prepared` and extends
  `expires_at` to at least 30 minutes (business-app onboarding with the phone takes a while). The screen polls every
  10 seconds while a launched attempt waits.
- The page keeps the window the SDK opens (`window.open` wrapped only around `FB.login`). When that window closes and
  Facebook has not answered within 8 seconds, it reports `NO_CALLBACK`; a "Meta penceresine dön" button focuses the
  open window; leaving the page while the window is open asks for confirmation. A blocked window says so.
- Responses: `report` returns 409 `{saved:false, message}` when the attempt no longer takes the entry (expired, or an
  ending on an attempt that is not open). `whatsapp/connect/*` requests that expect JSON get JSON errors (401 / 403 /
  409 / 419 / 422 / 429) instead of a login redirect or HTML. A connect page opened from another session goes back to
  the WhatsApp screen with the reason; the screen shows the connect link only to the session that started it.
- Reset (Ayarlar › "Bağlantıyı sıfırla", `WhatsAppConnection::reset()`): removes the Meta app id and configuration id
  (stored empty so an old `WHATSAPP_SIGNUP_CONFIG_ID` is not offered again), the bound number, every secret (access
  token, app secret, verify token), all attempts, conversations, messages and webhook receipts. Keeps `ai_model`,
  `automatic_suggestions`, `business_context` and the KVKK retention. Refused while a connection is completing.
  Nothing is changed at Meta.
- Connect-page steps follow Embedded Signup v4: pick the portfolio, enter the number used in the WhatsApp Business
  app (Meta starts coexistence itself), approve on the phone and share chats, "Bitti".
- Tests: `WhatsAppSignupFlowTest` (diary, relaunch after cancel, `NO_CALLBACK`, 409, other session, JSON 401, reset).
  The page script was exercised in Chromium against a stubbed SDK (closed window without answer, finish + code,
  blocked window, page left, cancel). Not verified against a live Meta popup.

## 2026-12-04 — Phone backup import and the brain

The Meta coexistence connection is suspended (the app-owner portfolio cannot be picked in Embedded Signup and the
number must stay in the advertising portfolio). The inbox is filled from the phone instead.

- Upload: Android WhatsApp Business end-to-end encrypted backup, `msgstore.db.crypt15` with the 64-digit key.
  `POST operator.whatsapp.backup` `{name, size}` returns `{id, chunk}`; the browser sends raw 4 MB pieces to
  `operator.whatsapp.backup.chunk?offset=N` (409 `{expected_offset}` resumes). Max 2 GB. crypt14 / crypt12 are refused
  with how to take a 64-digit-key backup. One import at a time (`whatsapp_backup_imports`); the file lives in
  `storage/app/private/whatsapp-backups/`.
- Extract (`ExtractWhatsAppBackup`, heavy queue): key = HMAC-SHA256(HMAC-SHA256(zeros, root), "backup encryption\x01");
  AES-256-GCM with the IV from the protobuf prefix; zlib payload is the SQLite msgstore. Modern (`message`/`chat`/`jid`
  + `jid_map`) and legacy (`messages`) schemas are read. One-to-one chats only; system messages skipped; media become
  placeholders. Known message ids are skipped, so a newer backup adds only the missing messages. A wrong key returns the
  import to `uploaded` and keeps the file; completion or failure deletes the file. The key that decrypted a backup
  is kept encrypted in `config.backup_key` (operator's choice): a later upload is queued for extraction as soon as its
  last piece arrives, and an empty key on "Çıkar" uses it. "Kayıtlı anahtarı sil" and the reset remove it.
- Brain (`WhatsAppBrain`, `LearnWhatsAppBrain`, route `whatsapp.brain`, OpenAI with the screen's model): learned once
  after the first extraction and on "Yeniden öğren". Reads up to 80 recent two-sided chats × 30 messages (150k chars),
  contacts pseudonymised. Stores summary, services, quoted prices with dates, FAQ, tone, policies, avoid and
  open_questions in `config.brain`. Reply drafts get it as `learned_profile`; the operator's `business_context`
  ("Talimatlarım") wins and learned prices are flagged as earlier quotes.
- Screen: backup card, brain card ("Senden istediklerim", learned sections, instructions), then the Meta setup folded
  under "Canlı bağlantı (Meta) — askıda". Backup chats show "Yedekten" and a "Mesaj üret" button; no automatic drafts
  and no reply-window badge for them.
- Tests: `WhatsAppBackupImportTest`. Not yet verified against a real phone backup.
- Server requirement: reading the decrypted msgstore needs `pdo_sqlite` (`php<version>-sqlite3`; staging runs PHP 8.5 and got `php8.5-sqlite3` on 2026-10-05); production runs on
  PostgreSQL, so the extension may be missing. Without it the key is still checked and saved, the import returns to
  `uploaded` with the install command, and "Çıkar" with an empty key runs it again. Other database errors show the
  driver message.

