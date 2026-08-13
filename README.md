# Savvy CFO Cost Savings Pro Tool

PHP application for teams to track vendor costs, cancellation intent, and savings estimates. Data is stored in MySQL; optional GoHighLevel sync runs for reminder emails (and contact upsert).

## Features

- **Username + password login** — Sessions store `user_id`, `org_id`, and app role (`admin` / `member`). Invitation email via Gmail API OAuth ([includes/mail.php](includes/mail.php) → `sendInviteEmail`).
- **Organizations (up to 10 users)** — Admin invites members by email; registration completes at [public/register.php](public/register.php).
- **Vendor grid** — Manager assignment, public/confidential visibility, purpose of subscription, cancellation deadline, last payment date; auto-save to `cost_calculator_items` (PDO / [src/VendorService.php](src/VendorService.php)).
- **CSV import** — QuickBooks “Transaction Detail by Account” exports (primary): upload shows a checklist of GL account sections; only payees (Name column) under selected accounts are imported, with account stored on raw transactions ([src/CsvImport.php](src/CsvImport.php)). Legacy “Transaction List by Vendor” CSVs still import in one step without the account picker. Custom column-mapped CSV is also supported.
- **QuickBooks Online sync** — Admin configures OAuth Client ID/Secret in Settings, connects the company, then uses Data → **Sync with QBO** to pull a date range via the General Ledger / Transaction List reports (GL account picker, same flow as CSV) ([src/QboService.php](src/QboService.php)).
- **Exports** — Excel (PhpSpreadsheet) and PDF (Dompdf) for vendor list and executive summary ([src/ExportService.php](src/ExportService.php)).
- **Email reminders** — Cron script [public/cron_reminders.php](public/cron_reminders.php) sends cancellation deadlines (T−7, T, T+7) and monthly renewal summaries through **GoHighLevel Conversations API** (LeadConnector).
- **Ask AI** — Perplexity Chat Completions when `PERPLEXITY_API_KEY` is set; otherwise OpenAI (`OPENAI_API_KEY`). 50 requests per user per month ([src/AiService.php](src/AiService.php)); see `config.example.php` for `AI_MODEL`, `AI_MAX_TOKENS`, and `AI_TEMPERATURE`.
- **GoHighLevel** — Reminder sends upsert the recipient as a contact (tag `Cost Savings Pro`) then email via Conversations.

## Layout

```
├── public/
│   ├── index.php                    # Main app (+ Gmail / QBO OAuth pages)
│   ├── register.php                 # Invitation registration
│   ├── cron_reminders.php           # Scheduled reminder jobs
│   └── cron_refresh_gmail_token.php # Gmail token refresh
├── includes/
│   ├── mail.php                     # Invites (Gmail) + reminders (GHL)
│   ├── ghl.php                      # GHL upsert + Conversations send
│   ├── GmailService.php             # Gmail API OAuth client
│   ├── gmail_handlers.php           # Gmail OAuth setup routes
│   ├── qbo_handlers.php             # QuickBooks OAuth connect/callback
│   └── actions.php                  # POST handlers
├── src/                             # PSR-4 CostSavings namespace (includes QboService)
├── composer.json
├── config.php                       # Not in repo
├── db_config.php                    # PDO + migrations
└── README.md
```

## Configuration

1. Copy `config.example.php` to `config.php`, then set `CACHE_DIR`, **Gmail OAuth**, **GHL**, database credentials, and at least one of `PERPLEXITY_API_KEY` or `OPENAI_API_KEY` for Ask AI. Set **`BASE_URL`** to your public app URL with trailing slash (e.g. `https://yourdomain.com/public/`) so invitation emails use correct links. Optionally set `QBO_REDIRECT_URI` if you need an override.
2. **Composer:** From the project root run `composer install` (requires PHP with Composer) for PhpSpreadsheet, Dompdf, and `google/apiclient`.
3. **Seed admin (local testing):** Set `SEED_ADMIN_PASSWORD` in the environment or in `config.php`. Leave empty in production if you do not want a seeded account.
4. **Cron:** Schedule `php public/cron_reminders.php` daily (or HTTP with `CRON_SECRET`). Schedule `php public/cron_refresh_gmail_token.php` every 30 minutes.
5. **Gmail OAuth (invites):**
   - Set `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI`, `GMAIL_OAUTH_SETUP_KEY`.
   - Set `SMTP_FROM_EMAIL` / `SMTP_FROM_NAME` to the Workspace mailbox you authorize (e.g. `contactus@savvycfo.com`).
   - Register the redirect URI in Google Cloud Console.
   - Complete one-time setup: `{BASE_URL}index.php?page=gmail-auth&key={GMAIL_OAUTH_SETUP_KEY}`
   - Sign in as the sender mailbox and grant `mail.google.com`.
6. **QuickBooks Online (vendor sync):**
   - Create an app in the [Intuit Developer](https://developer.intuit.com/) portal with accounting scope `com.intuit.quickbooks.accounting` only (no OpenID / payments scopes).
   - In `config.php` (or env) set:
     - `QBO_CLIENT_ID` / `QBO_CLIENT_SECRET` — your single Intuit developer app
     - `QBO_ENVIRONMENT` — `production` or `sandbox`
     - `QBO_REDIRECT_URI` — optional; default `{BASE_URL}index.php?page=qbo-callback`
     - `QBO_TOKEN_ENCRYPTION_KEY` — random secret for encrypting refresh tokens at rest
   - Register the redirect URI on the Intuit app.
   - Admin → Settings → **Connect to QuickBooks** (each customer company authorizes once). App Client ID/Secret are not entered in the UI.
   - Per org: company `realmId` + encrypted refresh token. No Intuit user IDs. Access tokens: process memory only.
   - Data → **Sync with QBO** → date range → select accounts → import.
7. **GoHighLevel (reminders):**
   - Set `GHL_API_KEY` (Private Integration Token with Contacts + Conversations scopes).
   - Set `GHL_LOCATION_ID` to the **same location** used by Scorecard Pro.
   - Set `GHL_FROM_EMAIL` to `no-reply@savvycfo.com` (LeadConnector / `mail.savvycfo.com` must allow this From).
   - Confirm a manual GHL Conversations send from `no-reply@savvycfo.com` works before relying on cron.

## Security note

Do not commit production secrets. Prefer environment variables for passwords and API keys.

### Intuit / QBO attestations (Payments & token handling)

| Requirement | Implementation |
|-------------|----------------|
| App does not automate merchant application authorization UI | OAuth is a full browser redirect to Intuit; no automated form fill of merchant UI. |
| App does not request/store user’s Intuit ID | Scope is accounting only (no OpenID). Identity claims are dropped; only company `realmId` is kept for API context. |
| App encrypts access tokens before storing | Access tokens are never stored. Refresh tokens are AES-256-GCM encrypted (`QBO_TOKEN_ENCRYPTION_KEY`). App secret stays in config/env. |
| App stores access tokens in volatile memory only | Access tokens held in process memory (`QboService` static cache) for the request lifecycle, then discarded. |

## Requirements

- PHP 8+ with `pdo_mysql`, `curl`, sessions.
- MySQL; `db_config.php` creates the database and applies migrations (including `cs_gmail_tokens`, `org_qbo_connections`).
- Composer dependencies installed (`vendor/`).
