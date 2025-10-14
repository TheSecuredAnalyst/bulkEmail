# Bulk Emailer (.env-based, SMTP/SendGrid)

Production-grade CLI bulk emailer with secrets in `.env`, CSV recipients, HTML templates, CC/BCC, rate limiting, retries, logs, and optional DKIM. Works with SMTP or SendGrid.

## Contents
- [Features](#features)
- [Architecture](#architecture)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [CSV Schema](#csv-schema)
- [Templates & Tokens](#templates--tokens)
- [Usage](#usage)
- [CC/BCC Modes](#ccbcc-modes)
- [Internal vs External Sends](#internal-vs-external-sends)
- [Deliverability](#deliverability)
- [Troubleshooting](#troubleshooting)
- [Security](#security)
- [License](#license)

## Features
- SMTP **or** SendGrid (toggle via `.env`)
- `.env` for all secrets — nothing hardcoded
- CSV-driven recipients; tokenized HTML template
- **CC/BCC**: global via `.env` and per-recipient via CSV
- Auto `{{today}}` (Africa/Lagos) and `{{ref_id}}` per row
- Rate limiting, retries, structured logs
- Optional DKIM signing
- “Internal directive” mode (suppresses List-Unsubscribe)

## Architecture
- `bulk_emailer.php` — CLI entrypoint
- `.env` — runtime config (never commit)
- `templates/notice.html` — brand-neutral template
- `data/recipients.csv` — target list (gitignored)
- Logs at `logs/mailer.log` (gitignored)

## Requirements
- PHP 8.0+
- Composer
- SMTP account or SendGrid API key

## Installation
```bash
composer install --no-dev --prefer-dist
cp .env.example .env
# edit .env with your values
Configuration
Set in .env (see .env.example for full list):

MAIL_TRANSPORT=smtp|sendgrid

For SMTP: SMTP_HOST, SMTP_PORT, SMTP_SECURE, SMTP_USER, SMTP_PASS

For SendGrid: SENDGRID_API_KEY

Sender: MAIL_FROM, MAIL_FROM_NAME, (optional) MAIL_REPLY_TO

Paths: TEMPLATE_PATH=templates/notice.html, RECIPIENTS_CSV=data/recipients.csv, LOG_PATH=logs/mailer.log

Behavior: RATE_LIMIT_PER_MINUTE, MAX_RETRIES, RETRY_DELAY_SECONDS

Optional: GLOBAL_CC, GLOBAL_BCC, UNSUB_BASE, INTERNAL_DIRECTIVE=true|false

CSV Schema
Header row is required. Suggested minimal schema:

pgsql
Copy code
email,name,cc,bcc
email required; others optional (comma-separated for cc/bcc).

You do not need today or ref_id; they are injected automatically.

Example:

code
email,name,cc,bcc
alice@example.org,Alice,,
bob@example.org,Bob,,archive@example.org

Templates & Tokens
Use templates/notice.html. Available tokens:

{{name}}, {{email}}, {{today}} (e.g., 14 Oct 2025), {{ref_id}} (e.g., 20251014-0001)

You may add arbitrary tokens via CSV headers (e.g., logo_url, logo_href, org_name, org_unit)

Snippet:

html
code
<a href="{{logo_href}}" target="_blank" style="text-decoration:none;">
  <img src="{{logo_url}}" alt="Logo" style="display:block; height:52px; width:auto; border:0;">
</a>
<p>Dear {{name}},</p>

Usage
##Dry run (no emails sent; logs only):

code
DRY_RUN=true php bulk_emailer.php

##Test single inbox:

code
TEST_MODE=true TEST_TO=you@example.org php bulk_emailer.php

##Send for real:
code
php bulk_emailer.php
CC/BCC Modes
Global: set GLOBAL_CC / GLOBAL_BCC in .env (comma-separated).

Per-recipient: add cc / bcc columns in CSV.

Tip: use BCC for audit/compliance to avoid exposing recipients.

Internal vs External Sends
Internal memos: set INTERNAL_DIRECTIVE=true (suppresses List-Unsubscribe).

External/campaigns: INTERNAL_DIRECTIVE=false and set UNSUB_BASE=https://example.org/unsub.

Deliverability
Configure SPF/DKIM/DMARC for your sending domain.

Keep RATE_LIMIT_PER_MINUTE conservative when warming up.

Clean HTML + automatic plain-text part; avoid spammy content.

Maintain list hygiene; handle bounces (future improvement).

Troubleshooting
Dotenv class not found: run composer install; ensure vendor/autoload.php is required.

No CC/BCC received: verify CSV headers include cc,bcc; check spam and rate limits.

Gmail SMTP: use an App Password with 2FA; standard passwords often fail.

Security
Never commit .env, real CSVs, logs, or keys. See .gitignore.

Use .env.example with example.org placeholders for documentation only.

License
MIT — see LICENSE.