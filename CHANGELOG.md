# Changelog

All notable changes to this project are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/).

## [1.0.0] - 2026-09-29

### Added
- Cause list scrapers for all Nepal courts:
  - Supreme Court (सर्वोच्च अदालत) — daily, weekly, supplementary
  - High Court (उच्च अदालत) — daily, weekly, supplementary
  - District Court (जिल्ला अदालत) — daily, weekly, supplementary
  - Special Court (विशेष अदालत) — daily, weekly, supplementary
  - Consumer Court (उपभोक्ता अदालत) — daily, weekly, supplementary
- `CauseListClient` unified library facade.
- Framework-agnostic JSON REST API (`NepalCauseList\Http\Api`) with a front
  controller in `public/index.php`.
- Bikram Sambat (BS) calendar helper.
- On-disk response caching to keep load off the court websites.
- Docker Compose setup bundling a Selenium container for the Supreme Court's
  F5 WAF challenge.
- OpenAPI 3 specification, examples, and unit tests.
