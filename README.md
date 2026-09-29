# Nepal Cause List API

Fetch **daily, weekly and supplementary cause lists (पेशी सूची)** for every Nepal
court and get them back as clean JSON — through either a small PHP library or a
ready-to-run REST API.

Built for the Nepali legal-tech community. MIT licensed. No account, no scraping
glue code, no fighting with legacy government HTML.

| Court | Nepali | Daily | Weekly | Supplementary | Needs court id |
|-------|--------|:-----:|:------:|:-------------:|:--------------:|
| Supreme Court | सर्वोच्च अदालत | ✅ | ✅ | ✅ | — |
| High Court | उच्च अदालत | ✅ | ✅ | ✅ | ✅ |
| District Court | जिल्ला अदालत | ✅ | ✅ | ✅ | ✅ |
| Special Court | विशेष अदालत | ✅ | ✅ | ✅ | — |
| Consumer Court | उपभोक्ता अदालत | ✅ | ✅ | ✅ | — |

Dates are **Bikram Sambat (BS)**, formatted `YYYY-MM-DD` (e.g. `2083-06-12`).

---

## Quick start

### Option A — Docker (REST API + Selenium)

```bash
git clone https://github.com/prosaugat/nepal-cause-list-api.git
cd nepal-cause-list-api
docker compose up --build
```

Then:

```bash
curl "http://localhost:8080/v1/specialcourt/cause-lists/daily?date=2083-06-12"
```

The bundled Selenium container is only used to clear the Supreme Court's WAF
challenge (see [How the Supreme Court works](#how-the-supreme-court-works)).
Every other court works over plain HTTP.

### Option B — Use it as a PHP library

Not on Packagist yet, so install straight from GitHub. Add the repository to
your project's `composer.json`:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/prosaugat/nepal-cause-list-api" }
    ]
}
```

Then require it:

```bash
composer require prosaugat/nepal-cause-list-api:dev-main
```

```php
use NepalCauseList\CauseListClient;

$client = new CauseListClient();

// Courts without a court id
$special = $client->fetch('specialcourt', 'daily', '2083-06-12');
echo $special['total_cases'];

// Courts that need an id (see $client->highCourts() / districtCourts())
$high = $client->fetch('highcourt', 'weekly', '2083-06-12', courtId: 1);

// Supreme Court (needs a Selenium hub — see below)
$supreme = $client->fetch('supremecourt', 'daily', '2083-06-12');
```

### Option C — Run the API without Docker

```bash
composer install
composer serve          # php -S localhost:8080 -t public
```

For the Supreme Court you also need a Selenium hub. The easiest way:

```bash
docker run -d -p 4444:4444 --shm-size=2g selenium/standalone-chrome:latest
```

---

## API reference

Base URL: `http://localhost:8080`

| Method & path | Description |
|---|---|
| `GET /` | API info and health |
| `GET /v1/courts` | Supported courts + whether each needs a court id |
| `GET /v1/today` | Today's date in Bikram Sambat |
| `GET /v1/highcourt/courts` | High court directory (`id` + names) |
| `GET /v1/districtcourt/courts` | District court directory (`id` + names) |
| `GET /v1/{court}/cause-lists/{listType}` | Fetch a cause list |

Query parameters for the cause-list endpoint:

- `date` — BS date `YYYY-MM-DD`. Defaults to today.
- `court_id` — **required** for `highcourt` and `districtcourt`.

A full OpenAPI 3 spec lives in [`docs/openapi.yaml`](docs/openapi.yaml), and more
curl examples in [`examples/curl.md`](examples/curl.md).

### Example response

```jsonc
{
  "success": true,
  "cached": false,
  "court": "specialcourt",
  "list_type": "daily",
  "date": "2083-06-12",
  "data": {
    "success": true,
    "court_name": "विशेष अदालत",
    "court_name_en": "Special Court",
    "list_type": "daily",
    "date": "2083-06-12",
    "fetched_at": "2026-09-29T10:24:00+00:00",
    "source_url": "https://supremecourt.gov.np/special/syspublic.php?d=reports&f=daily_public",
    "total_benches": 2,
    "total_cases": 15,
    "bench_options": [ { "value": "bench_1", "text": "इजलास १" } ],
    "benches": [
      {
        "bench_id": "bench_1",
        "bench_label": "इजलास १",
        "judges": ["बासुदेव आचार्य", "हेमन्त रावल", "उमेश कोइराला"],
        "entries_count": 4,
        "entries": [
          {
            "serial": "१",
            "case_number": "082-CR-0144",
            "subject": "भ्रष्टाचार ( रकम हिनामिना )",
            "parties": "नेपाल सरकार",
            "remarks": "थुनुवा | आदेश | दफा ७(क) बमोजिमको साक्षी बुझ्ने"
          }
        ]
      }
    ]
  }
}
```

---

## How it works

Each court runs a different (mostly legacy) case-management system, so there is a
dedicated scraper per court, all sharing the same output shape:

- **District & High courts** — plain concurrent HTTP (Guzzle), one request per
  bench/weekday, parsed with Symfony DomCrawler.
- **Special & Consumer courts** — same legacy CMS as the Supreme Court but
  **not** behind a WAF, so plain HTTP works too.
- **Supreme Court** — see below.

Parsing is content-based rather than column-based: each data row is located by
its case-number cell, which survives the malformed tables and legacy Nepali
fonts the court sites emit.

### How the Supreme Court works

The Supreme Court site (`supremecourt.gov.np/lic/sys.php`) sits behind an **F5
WAF** that blocks plain HTTP. Instead of driving the whole flow through a browser
(slow — one round-trip per bench), we use Selenium **once** to solve the F5
challenge, lift the resulting cookies into a Guzzle client, then run every data
request over fast concurrent HTTP. Point the scraper at a Selenium hub with:

```
SELENIUM_HUB=http://localhost:4444/wd/hub
```

Docker Compose wires this up automatically.

---

## Configuration

Copy `.env.example` to `.env` (or set real environment variables):

| Variable | Default | Purpose |
|---|---|---|
| `SELENIUM_HUB` | `http://localhost:4444/wd/hub` | Selenium hub for the Supreme Court |
| `CACHE_DIR` | system temp dir | Where the API caches responses |
| `CACHE_TTL` | `1800` | Cache lifetime in seconds |

Responses are cached on disk so repeated requests do not hit the court websites.
Bring your own cache (Redis, APCu, PSR-16) by swapping `FileCache` in
`public/index.php`.

---

## Using it from other languages

It is just JSON over HTTP — call it from Node, Python, Go, the browser, anywhere:

```js
const res = await fetch(
  "http://localhost:8080/v1/consumercourt/cause-lists/weekly?date=2083-06-12"
);
const { data } = await res.json();
console.log(data.total_cases);
```

---

## Please use it responsibly

This project reads **publicly available** cause lists published by Nepal's courts
and reshapes them into JSON. It does not bypass authentication or touch any
non-public data. To keep it welcome:

- keep caching on and avoid hammering the court websites,
- attribute the source (the data belongs to the respective courts),
- treat the data as informational — the court's own publication is authoritative.

---

## Roadmap

- [ ] Case-status / case-detail lookups
- [ ] Decided-cases endpoints
- [ ] Optional webhook / polling for new lists
- [ ] Published Postman collection

Ideas and PRs welcome — see [CONTRIBUTING.md](CONTRIBUTING.md).

## License

[MIT](LICENSE). Data belongs to the respective courts of Nepal.
