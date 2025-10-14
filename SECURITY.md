# Security Policy

## Supported versions
This is a community tool; security fixes are provided on a best-effort basis.

## Reporting a vulnerability
Please **do not** open public issues for security reports.

- Email: simebgo@gmail.com
- Or use GitHub’s private **Security Advisory** (if enabled on this repo)

Provide:
- A clear description and steps to reproduce
- A minimal sample `.env.example` (no secrets)
- Affected version/commit hash and environment (OS, PHP version)

We aim to acknowledge reports within 5 business days.

## Secrets & data handling (contributors)
- Never commit `.env`, keys (`*.key`, `*.pem`), logs, or real recipient CSVs.
- Use only `example.org` placeholders in docs and samples.
- Treat `logs/mailer.log` (if generated locally) as confidential; do not attach to issues.

## Safe defaults
- The repository ships with `.env.example` only.
- `.gitignore` blocks `.env`, data files, logs, and keys.
- Public templates are brand-neutral and generic.
