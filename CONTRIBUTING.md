# Contributing

Thanks for your interest! Please read this before submitting PRs.

## Ground rules
- **No secrets or private data** in commits. That includes `.env`, recipient CSVs, logs, keys, screenshots of admin consoles, or organization-specific directives.
- Keep templates **generic**. Use `example.org` and neutral branding in public templates.
- Follow existing code style (PHP 8+, strict types). Prefer small, focused PRs.

## How to contribute
1. Fork the repo and create your branch from `main`.
2. Run `composer install` and lint: `php -l bulk_emailer.php`.
3. If you modify CSV handling, update the sample: `data/recipients.sample.csv`.
4. Update docs if behavior changes (`README.md`, `.env.example`).
5. Open a PR with:
   - What changed and why
   - Testing steps
   - Any security considerations

## Commit messages
Use clear, conventional messages, e.g.:
- `feat: add GLOBAL_CC/BCC support`
- `fix: handle semicolon-delimited CSV locales`
- `docs: expand deliverability guidance`

## Code of conduct
Be respectful and constructive. We reserve the right to close PRs that include unsafe content or org-specific confidential material.
