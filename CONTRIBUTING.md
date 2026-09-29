# Contributing

Thanks for helping improve the Nepal Cause List API. 🙏

## Getting started

```bash
git clone https://github.com/prosaugat/nepal-cause-list-api.git
cd nepal-cause-list-api
composer install
composer test
```

## Ground rules

- The court websites are the source of truth. When their markup changes and a
  parser breaks, please include a small sample of the new HTML in the PR (with
  any personal data redacted) so the fix can be verified.
- Keep the scrapers polite: reuse the built-in caching, do not add tight retry
  loops, and do not increase request concurrency beyond what a human browsing
  the site would produce.
- Match the existing code style. Parsers are intentionally defensive because the
  upstream HTML is legacy and inconsistent.
- Add or update a test when you change behaviour. Network-dependent tests should
  be skipped by default so `composer test` stays offline and deterministic.

## Reporting a broken court

Open an issue with:
- the court and list type,
- the BS date you tried,
- what you expected vs. what you got,
- the `source_url` from the response, if present.

## Scope

This project only reads publicly available cause lists and reshapes them into
JSON. Please do not send PRs that add authentication bypasses, write operations,
or anything that targets non-public endpoints.
