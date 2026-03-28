# Savvy CFO Cost Savings Tool

PHP application for authenticated users to track vendor costs, cancellation intent, and savings estimates. Data is stored in MySQL; optional GoHighLevel sync runs when a user saves their role.

## Features

- **Email + OTP login** — OTP file is written under `CACHE_DIR` in [config.php](config.php); HTML email via PHPMailer when installed, otherwise PHP `mail()`.
- **Role selection** — Stored in the `users` table (PDO). Legacy `user_role` in `cache/resp_*.json` is migrated once into the database when present.
- **Cost calculator** — Grid of line items (vendor, cost, frequency, annual cost, cancel/keep, confirmed cancellation, notes) with auto-save to `cost_calculator_items` (MySQL via mysqli in [public/index.php](public/index.php)).
- **GoHighLevel** — On first role save, `syncContactToGHL()` creates tags and a contact for the location in config.

## Layout

```
├── public/
│   └── index.php      # Single entry: auth UI + calculator + POST actions
├── config.php         # CACHE_DIR, SMTP, GHL, database constants
├── db_config.php      # PDO helpers and `users` table bootstrap
├── cache/             # Optional legacy resp_*.json; OTP may use CACHE_DIR
└── README.md
```

## Configuration

1. Copy `config.example.php` to `config.php`, then set `CACHE_DIR` (must be writable for OTP), SMTP, GHL, and database credentials.
2. Ensure the web server document root points at `public/` (or map the app so `public/index.php` is the main URL).
3. **PHPMailer (optional):** Install under `public/vendor/phpmailer/phpmailer/` or one of the paths searched at the top of `index.php` for reliable SMTP.

## Security note

Do not commit production secrets. Prefer environment variables or a file outside the web root for credentials, and rotate any keys that have been exposed.

## Requirements

- PHP with `mysqli`, `pdo_mysql`, `curl` (if you add HTTP features later), sessions enabled.
- MySQL database matching `DB_*` in config; `db_config.php` can create the database and `users` table; the cost calculator table is created on first save.
