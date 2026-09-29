<?php

namespace NepalCauseList\Courts;

use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Chrome\ChromeOptions;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use Symfony\Component\DomCrawler\Crawler;
use NepalCauseList\Support\Log;

/**
 * Supreme Court cause list scraper (daily / weekly / supplementary).
 *
 * The main Supreme Court cause list (/lic/sys.php) sits behind an F5 WAF that
 * blocks plain HTTP. Rather than driving the whole flow through Selenium (slow —
 * one browser round-trip per bench), we use Selenium ONCE to solve the F5
 * challenge, lift the resulting cookies (TS…, f5_cspm, f5avr…, PHPSESSID) into a
 * Guzzle cookie jar, then run every data request over fast concurrent HTTP.
 *
 * Report endpoints (POST to sys.php):
 *   daily         f=daily_public          two-step: mode=showbench -> mode=show per bench
 *   weekly        f=weekly_public         one table per weekday (day=0..5)
 *   supplementary f=weekly_suppli_public  a single request with an empty
 *                                         `causelist` returns every type at once
 */
class SupremeCourtService
{
    private string $base = 'https://supremecourt.gov.np/lic/sys.php';
    private string $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    private array $weekdays = [
        0 => 'आइतबार (Sunday)',
        1 => 'सोमबार (Monday)',
        2 => 'मंगलबार (Tuesday)',
        3 => 'बुधबार (Wednesday)',
        4 => 'बिहीबार (Thursday)',
        5 => 'शुक्रबार (Friday)',
    ];

    private function reportUrl(string $f): string
    {
        return $this->base . '?d=reports&f=' . $f;
    }

    private function splitDate(string $bsDate): array
    {
        [$y, $m, $d] = array_pad(explode('-', $bsDate), 3, '01');
        return [$y, str_pad($m, 2, '0', STR_PAD_LEFT), str_pad($d, 2, '0', STR_PAD_LEFT)];
    }

    /**
     * Use Selenium once to pass the F5 WAF, then return a Guzzle client whose
     * cookie jar carries the WAF + PHP session cookies.
     */
    private function warmClient(string $warmUrl): Client
    {
        $driver = null;
        try {
            $chromeOptions = new ChromeOptions();
            $chromeOptions->addArguments([
                '--headless=new', '--no-sandbox', '--disable-dev-shm-usage', '--disable-gpu',
                '--window-size=1920,1080', '--disable-blink-features=AutomationControlled',
                '--user-agent=' . $this->ua, '--disable-extensions', '--disable-logging', '--log-level=3',
            ]);
            $caps = DesiredCapabilities::chrome();
            $caps->setCapability(ChromeOptions::CAPABILITY, $chromeOptions);
            $hub = getenv('SELENIUM_HUB') ?: 'http://localhost:4444/wd/hub';
            $driver = RemoteWebDriver::create($hub, $caps, 60000, 60000);

            $driver->get($warmUrl);
            // Poll until the F5 challenge clears: the page must no longer be
            // the "rejected" block page AND F5 cookies must be present. Check
            // first (the get() already waited for load) and only sleep/re-
            // navigate if the challenge has not cleared yet. Bounded so a slow
            // or failing warm cannot stall the whole request.
            for ($i = 0; $i < 3; $i++) {
                $source = $driver->getPageSource();
                $hasF5 = false;
                foreach ($driver->manage()->getCookies() as $c) {
                    if (stripos($c->getName(), 'f5') !== false) {
                        $hasF5 = true;
                        break;
                    }
                }
                if ($hasF5 && stripos($source, 'rejected') === false) {
                    break;
                }
                sleep(2);
                $driver->get($warmUrl);
            }

            $jar = new CookieJar();
            foreach ($driver->manage()->getCookies() as $c) {
                $jar->setCookie(new SetCookie([
                    'Name' => $c->getName(),
                    'Value' => $c->getValue(),
                    'Domain' => 'supremecourt.gov.np',
                    'Path' => '/',
                ]));
            }

            return new Client([
                'timeout' => 120,
                'verify' => false,
                'cookies' => $jar,
                'http_errors' => false,
                'headers' => [
                    'User-Agent' => $this->ua,
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.9,ne;q=0.8',
                ],
            ]);
        } finally {
            if ($driver) {
                try {
                    $driver->quit();
                } catch (\Throwable $e) {
                    // ignore
                }
            }
        }
    }

    // ---------------------------------------------------------------------
    // DAILY
    // ---------------------------------------------------------------------

    public function fetchDaily(string $bsDate): array
    {
        try {
            [$y, $m, $d] = $this->splitDate($bsDate);
            $url = $this->reportUrl('daily_public');

            // The F5 warm + showbench step is occasionally blocked or returns
            // an empty page even when the date has a list. Retry with a fresh
            // warm until we get bench options (or confirm the date is empty).
            $client = null;
            $benchOptions = [];
            for ($attempt = 1; $attempt <= 2; $attempt++) {
                $client = $this->warmClient($url);
                $res = $client->post($url, [
                    'form_params' => [
                        'mode' => 'showbench', 'syy' => $y, 'smm' => $m, 'sdd' => $d,
                        'imageField2.x' => 50, 'imageField2.y' => 10,
                    ],
                    'headers' => ['Referer' => $url],
                ]);
                $body = (string) $res->getBody();
                $benchOptions = $this->parseBenchOptions($body);

                if (!empty($benchOptions)) {
                    break;
                }
                // A valid page with no bench dropdown means the date genuinely
                // has no daily list. A blocked/challenge page needs a re-warm.
                $looksBlocked = stripos($body, 'rejected') !== false
                    || stripos($body, 'bench_type') === false && strlen($body) < 2000;
                if (!$looksBlocked) {
                    break;
                }
            }

            if (empty($benchOptions)) {
                return $this->emptyResult('daily', $bsDate, $url);
            }

            $requests = function () use ($url, $benchOptions, $y, $m, $d) {
                foreach ($benchOptions as $opt) {
                    $body = http_build_query([
                        'mode' => 'show', 'bench_type' => $opt['value'],
                        'syy' => $y, 'smm' => $m, 'sdd' => $d,
                        'sdate' => $y . $m . $d,
                        'imageField2.x' => 50, 'imageField2.y' => 10,
                    ]);
                    yield new Request('POST', $url, [
                        'Content-Type' => 'application/x-www-form-urlencoded',
                        'Referer' => $url,
                    ], $body);
                }
            };

            $benchHtml = [];
            (new Pool($client, $requests(), [
                'concurrency' => 8,
                'fulfilled' => function ($response, $index) use (&$benchHtml) {
                    $benchHtml[$index] = (string) $response->getBody();
                },
                'rejected' => function ($reason, $index) use (&$benchHtml) {
                    $benchHtml[$index] = '';
                },
            ]))->promise()->wait();

            $benches = [];
            foreach ($benchOptions as $i => $opt) {
                $entries = $this->parseCases($benchHtml[$i] ?? '');
                if (empty($entries)) {
                    continue;
                }
                $header = $this->parseBenchOption($opt['text']);
                $benches[] = [
                    'bench_id' => 'bench_' . (count($benches) + 1),
                    'bench_label' => $header['label'],
                    'bench_option' => $header['label'],
                    'judges' => $header['judges'],
                    'entries' => $entries,
                    'entries_count' => count($entries),
                ];
            }

            return $this->result('daily', $bsDate, $benches, $url);
        } catch (\Throwable $e) {
            return $this->errorResult('daily', $bsDate, $e);
        }
    }

    // ---------------------------------------------------------------------
    // WEEKLY (loop the six weekdays)
    // ---------------------------------------------------------------------

    public function fetchWeekly(string $bsDate): array
    {
        try {
            [$y, $m, $d] = $this->splitDate($bsDate);
            $ymd = $y . $m . $d;
            $url = $this->reportUrl('weekly_public');

            $requests = function ($client) use ($url, $ymd) {
                foreach (array_keys($this->weekdays) as $day) {
                    $body = http_build_query([
                        'date' => $ymd, 'mode' => 'show', 'yo' => 1, 'day' => $day,
                        'imageField.x' => 50, 'imageField.y' => 10,
                    ]);
                    yield $day => new Request('POST', $url, [
                        'Content-Type' => 'application/x-www-form-urlencoded',
                        'Referer' => $url,
                    ], $body);
                }
            };

            // Retry the warm + fetch if every weekday came back blocked/empty
            // (a failed F5 warm), but stop once we have any real content.
            $dayHtml = [];
            for ($attempt = 1; $attempt <= 2; $attempt++) {
                $client = $this->warmClient($url);
                $dayHtml = [];
                (new Pool($client, $requests($client), [
                    'concurrency' => 6,
                    'fulfilled' => function ($response, $index) use (&$dayHtml) {
                        $dayHtml[$index] = (string) $response->getBody();
                    },
                    'rejected' => function ($reason, $index) use (&$dayHtml) {
                        $dayHtml[$index] = '';
                    },
                ]))->promise()->wait();

                $blocked = true;
                foreach ($dayHtml as $html) {
                    if ($html !== '' && stripos($html, 'rejected') === false) {
                        $blocked = false;
                        break;
                    }
                }
                if (!$blocked) {
                    break;
                }
            }

            $benches = [];
            foreach ($this->weekdays as $day => $label) {
                $entries = $this->parseCases($dayHtml[$day] ?? '');
                if (empty($entries)) {
                    continue;
                }
                $benches[] = [
                    'bench_id' => 'day_' . $day,
                    'bench_label' => $label,
                    'bench_option' => $label,
                    'judges' => [],
                    'entries' => $entries,
                    'entries_count' => count($entries),
                ];
            }

            return $this->result('weekly', $bsDate, $benches, $url);
        } catch (\Throwable $e) {
            return $this->errorResult('weekly', $bsDate, $e);
        }
    }

    // ---------------------------------------------------------------------
    // SUPPLEMENTARY (single request with empty causelist returns all types)
    // ---------------------------------------------------------------------

    public function fetchSupplementary(string $bsDate): array
    {
        try {
            [$y, $m, $d] = $this->splitDate($bsDate);
            $url = $this->reportUrl('weekly_suppli_public');

            // Retry the warm + fetch while the response looks blocked.
            $entries = [];
            for ($attempt = 1; $attempt <= 2; $attempt++) {
                $client = $this->warmClient($url);
                $res = $client->post($url, [
                    'form_params' => [
                        'syy' => $y, 'smm' => $m, 'sdd' => $d,
                        'mode' => 'show', 'yo' => 1, 'causelist' => '',
                        'imageField.x' => 50, 'imageField.y' => 10,
                    ],
                    'headers' => ['Referer' => $url],
                ]);
                $body = (string) $res->getBody();
                $entries = $this->parseCases($body);
                if (!empty($entries) || stripos($body, 'rejected') === false) {
                    break;
                }
            }

            $benches = [];
            if (!empty($entries)) {
                $benches[] = [
                    'bench_id' => 'suppli_all',
                    'bench_label' => 'पूरक सूची (Supplementary)',
                    'bench_option' => 'पूरक सूची (Supplementary)',
                    'judges' => [],
                    'entries' => $entries,
                    'entries_count' => count($entries),
                ];
            }

            return $this->result('supplementary', $bsDate, $benches, $url);
        } catch (\Throwable $e) {
            return $this->errorResult('supplementary', $bsDate, $e);
        }
    }

    // ---------------------------------------------------------------------
    // Parsing helpers (shared with the High Court appeal layout)
    // ---------------------------------------------------------------------

    private function parseBenchOptions(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }
        $crawler = new Crawler($html);
        $options = [];
        try {
            $crawler->filter('select[name="bench_type"] option')->each(function (Crawler $o) use (&$options) {
                $value = trim($o->attr('value') ?? '');
                $text = $this->clean($o->text(''));
                if ($value !== '') {
                    $options[] = ['value' => $value, 'text' => $text];
                }
            });
        } catch (\Throwable $e) {
            // no select
        }
        return $options;
    }

    private function parseBenchOption(string $text): array
    {
        $text = $this->clean($text);
        $num = '';
        $judgesStr = $text;
        // Supreme Court labels look like "१. सपना प्रधान मल्ल र मेघराज पोखरेल ,"
        if (preg_match('/^([०-९\d]+)\s*[.।]+\s*(.*)$/u', $text, $mm)) {
            $num = trim($mm[1]);
            $judgesStr = $mm[2];
        }
        $judgesStr = trim(rtrim(trim($judgesStr), ','));
        $judges = [];
        foreach (preg_split('/\s+र\s+/u', $judgesStr) as $j) {
            $j = trim(rtrim(trim($j), ','));
            if ($j !== '') {
                $judges[] = $j;
            }
        }
        $label = $num !== '' ? ('इजलास ' . $num) : ($judgesStr !== '' ? $judgesStr : 'इजलास');
        return ['label' => $label, 'judges' => $judges];
    }

    /**
     * Content-based case-row parser (see HighCourtService for the rationale):
     * each data row is located by its case-number cell; subject sits before it,
     * parties after, trailing cells become remarks. Handles the malformed,
     * nested tables and legacy-font headers the court site emits.
     */
    private function parseCases(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }
        $crawler = new Crawler($html);
        $entries = [];
        $seen = [];

        try {
            $crawler->filter('tr')->each(function (Crawler $row) use (&$entries, &$seen) {
                $tds = $row->filter('td');
                $n = $tds->count();
                if ($n < 6 || $n > 15) {
                    return;
                }
                $cells = [];
                for ($i = 0; $i < $n; $i++) {
                    $cells[$i] = $this->clean($tds->eq($i)->text(''));
                }

                $ci = -1;
                $caseToken = '';
                foreach ($cells as $i => $v) {
                    if (preg_match('/\d{2,3}\s*-\s*[A-Za-z]{1,6}\s*-\s*\d+/u', $v, $cm)) {
                        $ci = $i;
                        $caseToken = $cm[0];
                        break;
                    }
                }
                if ($ci < 1) {
                    return;
                }

                $serial = $cells[0] ?? '';
                // Keep the case number clean; any trailing note in the same
                // cell (e.g. "( मिसिल नष्ट भइ प्राप्त भएको )") becomes a remark.
                $caseNumber = preg_replace('/\s*-\s*/u', '-', trim($caseToken));
                $caseNote = trim(str_replace($caseToken, '', $cells[$ci]));
                $subject = $cells[$ci - 1] ?? '';
                $parties = $cells[$ci + 1] ?? '';

                $remarkParts = [];
                if ($caseNote !== '') {
                    $remarkParts[] = $caseNote;
                }
                for ($j = $ci + 2; $j < $n; $j++) {
                    if (($cells[$j] ?? '') !== '') {
                        $remarkParts[] = $cells[$j];
                    }
                }
                $remarks = implode(' | ', array_unique($remarkParts));

                $key = $caseNumber . '|' . $parties . '|' . $serial;
                if (isset($seen[$key])) {
                    return;
                }
                $seen[$key] = true;

                $entries[] = [
                    'serial' => $serial,
                    'case_number' => $caseNumber,
                    'subject' => $subject,
                    'parties' => $parties,
                    'remarks' => $remarks,
                ];
            });
        } catch (\Throwable $e) {
            // ignore
        }

        return $entries;
    }

    // ---------------------------------------------------------------------
    // Result shaping
    // ---------------------------------------------------------------------

    private function result(string $listType, string $bsDate, array $benches, string $url): array
    {
        $totalCases = array_sum(array_map(fn ($b) => $b['entries_count'], $benches));
        return [
            'success' => true,
            'court_name' => 'सर्वोच्च अदालत',
            'court_name_en' => 'Supreme Court of Nepal',
            'list_type' => $listType,
            'date' => $bsDate,
            'fetched_at' => date('c'),
            'source_url' => $url,
            'total_benches' => count($benches),
            'total_cases' => $totalCases,
            'bench_options' => array_map(fn ($b) => ['value' => $b['bench_id'], 'text' => $b['bench_label']], $benches),
            'benches' => $benches,
        ];
    }

    private function emptyResult(string $listType, string $bsDate, string $url): array
    {
        return $this->result($listType, $bsDate, [], $url);
    }

    private function errorResult(string $listType, string $bsDate, \Throwable $e): array
    {
        Log::error('Supreme court fetch failed', [
            'list_type' => $listType, 'error' => $e->getMessage(),
        ]);
        return [
            'success' => false,
            'error' => 'Failed to fetch cause list: ' . $e->getMessage(),
            'date' => $bsDate,
        ];
    }

    private function clean(string $text): string
    {
        $text = str_replace(["\xc2\xa0", '&nbsp;'], ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim($text);
    }
}
