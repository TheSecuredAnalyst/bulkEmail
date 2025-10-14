# Bulk Emailer (.env-based, SMTP/SendGrid)

A production-ready CLI bulk emailer. Credentials live in `.env` (not in code).  
Supports SMTP or SendGrid, CSV recipients, CC/BCC, per-recipient tokens, rate limiting, retries, logs, and optional DKIM.

## Features (brief)
- SMTP **or** SendGrid via `.env`
- CSV + HTML template with tokens (`{{name}}`, `{{today}}`, `{{ref_id}}`)
- Global + per-recipient CC/BCC
- Internal directive mode (suppresses List-Unsubscribe)
- Rate limiting, retries, logs; optional DKIM

## Quick start
```bash
- composer install --no-dev --prefer-dist
- cp .env.example .env
- edit .env with your values
- php bulk_emailer.php   # DRY_RUN/TEST_MODE available via env

CSV schema (summary)
Header row required. Suggested:

"email,name,cc,bcc"
##email required; others optional.

You don’t need today or ref_id — injected automatically.

Template
Put your HTML in templates/notice.html.
Tokens available by default: {{name}}, {{email}}, {{today}}, {{ref_id}}.

Safety
##Never commit .env, real CSVs, logs, keys.

Use .env.example only; keep real creds outside Git.

See .gitignore for blocked paths.

yaml
Copy code

---

## 2C) Create the folders for those paths (once)

```bash

"mkdir -p templates"  

(We’ll add a generic templates/notice.html in a later step.)

