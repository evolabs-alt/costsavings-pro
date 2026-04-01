# Savvy CFO Cost Savings Pro Tool

PHP application for teams to track vendor costs, cancellation intent, and savings estimates. Data is stored in MySQL; optional GoHighLevel sync runs when a user saves their demographic role.

## Features

- **Username + password login** — Sessions store `user_id`, `org_id`, and app role (`admin` / `member`). PHPMailer or PHP `mail()` via [includes/mail.php](includes/mail.php).
- **Organizations (up to 10 users)** — Admin invites members by email; registration completes at [public/register.php](public/register.php).
- **Vendor grid** — Manager assignment, public/confidential visibility, purpose of subscription, cancellation deadline, last payment date; auto-save to `cost_calculator_items` (PDO / [src/VendorService.php](src/VendorService.php)).
- **CSV import** — QuickBooks-style “Cost Savings - Transaction List by Vendor” exports ([src/CsvImport.php](src/CsvImport.php)).
- **Exports** — Excel (PhpSpreadsheet) and PDF (Dompdf) for vendor list and executive summary ([src/ExportService.php](src/ExportService.php)).
- **Email reminders** — Cron script [public/cron_reminders.php](public/cron_reminders.php) for cancellation deadlines (T−7, T, T+7) and monthly renewal summaries.
- **Ask AI** — Perplexity Chat Completions when `PERPLEXITY_API_KEY` is set; otherwise OpenAI (`OPENAI_API_KEY`). 50 requests per user per month ([src/AiService.php](src/AiService.php)); see `config.example.php` for `AI_MODEL`, `AI_MAX_TOKENS`, and `AI_TEMPERATURE`.
- **GoHighLevel** — On first demographic role save, `syncContactToGHL()` creates tags and a contact.

## Layout

```
├── public/
│   ├── index.php           # Main app
│   ├── register.php        # Invitation registration
│   └── cron_reminders.php  # Scheduled jobs (CLI or HTTP with key)
├── includes/
│   ├── mail.php            # SMTP / PHPMailer
│   └── actions.php         # POST handlers
├── src/                    # PSR-4 CostSavings namespace
├── composer.json
├── config.php              # Not in repo
├── db_config.php           # PDO + migrations
└── README.md
```

## Configuration

1. Copy `config.example.php` to `config.php`, then set `CACHE_DIR`, SMTP, GHL, database credentials, and at least one of `PERPLEXITY_API_KEY` or `OPENAI_API_KEY` (via environment or defines) for Ask AI. Set **`BASE_URL`** to your public app URL with trailing slash (e.g. `https://yourdomain.com/public/`) so invitation emails use correct links; if omitted, the app builds the URL from the current request (set `BASE_URL` when behind a reverse proxy or if invites point to the wrong host).
2. **Composer:** From the project root run `composer install` (requires PHP with Composer) to install PhpSpreadsheet and Dompdf for exports.
3. **Seed admin (local testing):** Set `SEED_ADMIN_PASSWORD` in the environment or in `config.php` so the first bootstrap can create the seeded admin (`SEED_ADMIN_USERNAME` / `SEED_ADMIN_EMAIL` in `config.example.php`). Leave empty in production if you do not want a seeded account.
4. **Cron:** Schedule `php public/cron_reminders.php` daily (or call via HTTP with `CRON_SECRET` if defined in config).
5. **PHPMailer (optional):** Install under `public/vendor/phpmailer/phpmailer/` or one of the paths in [includes/mail.php](includes/mail.php).

## Security note

Do not commit production secrets. Prefer environment variables for passwords and API keys.

## Requirements

- PHP 8+ with `pdo_mysql`, `curl`, sessions.
- MySQL; `db_config.php` creates the database and applies migrations.
