<?php

namespace NepalCauseList\Courts;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use Symfony\Component\DomCrawler\Crawler;
use NepalCauseList\Support\Log;

/**
 * High Court (Appeal) cause list scraper.
 *
 * The /appeal/ subsystem on supremecourt.gov.np is reachable over plain HTTP
 * (the F5 WAF does not block these GET/POST requests the way it does the main
 * Supreme Court cause list), so no Selenium is required.
 *
 * Session model: the target court is selected server-side via
 *   syspublic.php?d=reports&f=courtselect&courtid=<id>
 * which stores the court in the PHP session. Subsequent report POSTs reuse that
 * session (same cookie jar), so we always GET courtselect first.
 *
 * Report endpoints (all POST to syspublic.php with the given f):
 *   daily        f=daily_public          two-step: mode=showbench -> mode=show per bench
 *   weekly       f=weekly_public         one table per weekday (day=0..5)
 *   supplementary f=weekly_suppli_public  one table per case-type (causelist)
 */
class HighCourtService
{
    private string $base = 'https://supremecourt.gov.np/appeal/syspublic.php';
    private string $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';

    /**
     * High courts (and their branch/temporary benches) with ids from the
     * courtselect page. name_en are transliterations for search.
     * @var array<int, array{ne:string, en:string}>
     */
    private array $courts = [
        1  => ['ne' => 'उच्च अदालत विराटनगर', 'en' => 'Biratnagar High Court'],
        90 => ['ne' => 'उच्च अदालत विराटनगर, अस्थायी इजलास ओखलढुंगा', 'en' => 'Biratnagar HC, Okhaldhunga Temporary Bench'],
        15 => ['ne' => 'उच्च अदालत विराटनगर, इलाम इजलास', 'en' => 'Biratnagar HC, Ilam Bench'],
        2  => ['ne' => 'उच्च अदालत विराटनगर, धनकुटा इजलास', 'en' => 'Biratnagar HC, Dhankuta Bench'],
        4  => ['ne' => 'उच्च अदालत जनकपुर', 'en' => 'Janakpur High Court'],
        91 => ['ne' => 'उच्च अदालत जनकपुर, अस्थायी इजलास वीरगन्ज', 'en' => 'Janakpur HC, Birgunj Temporary Bench'],
        3  => ['ne' => 'उच्च अदालत जनकपुर, राजविराज इजलास', 'en' => 'Janakpur HC, Rajbiraj Bench'],
        6  => ['ne' => 'उच्च अदालत पाटन', 'en' => 'Patan High Court'],
        5  => ['ne' => 'उच्च अदालत पाटन, हेटौंडा इजलास', 'en' => 'Patan HC, Hetauda Bench'],
        7  => ['ne' => 'उच्च अदालत पोखरा', 'en' => 'Pokhara High Court'],
        8  => ['ne' => 'उच्च अदालत पोखरा, बाग्लुङ्ग इजलास', 'en' => 'Pokhara HC, Baglung Bench'],
        10 => ['ne' => 'उच्च अदालत तुलसीपुर', 'en' => 'Tulsipur High Court'],
        14 => ['ne' => 'उच्च अदालत तुलसीपुर, नेपालगंज इजलास', 'en' => 'Tulsipur HC, Nepalgunj Bench'],
        9  => ['ne' => 'उच्च अदालत तुलसीपुर, बुटवल इजलास', 'en' => 'Tulsipur HC, Butwal Bench'],
        11 => ['ne' => 'उच्च अदालत सुर्खेत', 'en' => 'Surkhet High Court'],
        12 => ['ne' => 'उच्च अदालत सुर्खेत, जुम्ला इजलास', 'en' => 'Surkhet HC, Jumla Bench'],
        96 => ['ne' => 'उच्च अदालत दिपायल', 'en' => 'Dipayal High Court'],
        13 => ['ne' => 'उच्च अदालत दिपायल, महेन्द्रनगर इजलास', 'en' => 'Dipayal HC, Mahendranagar Bench'],
    ];

    /** Weekday labels for the weekly report (day index 0..5). */
    private array $weekdays = [
        0 => 'आईतबार (Sunday)',
        1 => 'सोमबार (Monday)',
        2 => 'मंगलबार (Tuesday)',
        3 => 'बुधबार (Wednesday)',
        4 => 'बिहीबार (Thursday)',
        5 => 'शुक्रबार (Friday)',
    ];

    /** Supplementary case-type options (causelist value => label). */
    private array $supplementaryTypes = [
        1 => 'मोही मुद्दा', 2 => 'मुद्दा', 3 => 'रिट', 4 => 'अंश मुद्दा', 5 => 'निवेदन',
        6 => 'शुरु मुद्दा', 7 => 'साना पकृतिका मुद्दा', 8 => 'पूर्ण बिषेश ईजलासमा पेश हुने रट',
        10 => 'थुनुवा मुद्दा', 11 => 'अन्य मुद्दा', 12 => 'पाप्त छैन', 14 => 'जालसाजी',
        15 => 'पुनरावलोकन', 16 => 'पूर्ण बिषेश ईजलासमा पेश हुने मुद्दा', 17 => 'निषधाज्ञा र परमादेश मात्र',
        18 => 'विपक्ष नझिकाएका मात्र', 22 => 'निवेदन', 23 => 'एकल ईजलासमा पेश हुने मुद्दा',
        24 => 'बाणिज्य ईजलासमा पेश हुने मुद्दा', 25 => 'झ।झि भएका थुनुवा', 26 => 'झ।झि नभएका थुनुवा',
        27 => 'झ.झि भएका सरकारवादी (थुनुवा बाहेक)', 28 => 'झ.झि नभएका सरकारवादी (थुनुवा बाहेक)',
        29 => 'झ.झि नभएका सप्तरीका मुद्दा', 30 => 'यव्क्तिबादी फौजदारी झ.झिकाइएका',
        31 => 'यव्क्तिबादी फौजदारी झ.नझिकाइएका', 35 => 'यव्क्तिबादी देवानी झ.झिकाइएका',
        36 => 'यव्क्तिबादी देवानी झ.नझिकाइएका', 37 => 'रिटमा निषेधाज्ञा मात्र',
        38 => 'अन्य निषेधाज्ञा समेतको वा वेगर (उत्प्रेषण लगायतका) रिट', 39 => '१८ महिना नाघेका मुद्दाहरु',
    ];

    /** @return array<int, array{id:int, name_ne:string, name_en:string}> */
    public function getCourts(): array
    {
        $out = [];
        foreach ($this->courts as $id => $n) {
            $out[] = ['id' => $id, 'name_ne' => $n['ne'], 'name_en' => $n['en']];
        }
        return $out;
    }

    public function hasCourt(int $id): bool
    {
        return isset($this->courts[$id]);
    }

    public function courtName(int $id): array
    {
        return $this->courts[$id] ?? ['ne' => "High Court {$id}", 'en' => "High Court {$id}"];
    }

    private function newSession(int $courtId): array
    {
        $jar = new CookieJar();
        $client = new Client([
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
        // Select the court server-side (stores it in the session).
        $client->get($this->base, [
            'query' => ['d' => 'reports', 'f' => 'courtselect', 'courtid' => $courtId],
        ]);
        return [$client, $jar];
    }

    private function reportUrl(string $f): string
    {
        return $this->base . '?d=reports&f=' . $f;
    }

    /** Split a BS date "2083-06-12" into [yyyy, mm, dd] strings. */
    private function splitDate(string $bsDate): array
    {
        [$y, $m, $d] = array_pad(explode('-', $bsDate), 3, '01');
        return [$y, str_pad($m, 2, '0', STR_PAD_LEFT), str_pad($d, 2, '0', STR_PAD_LEFT)];
    }

    // ---------------------------------------------------------------------
    // DAILY
    // ---------------------------------------------------------------------

    public function fetchDaily(int $courtId, string $bsDate): array
    {
        try {
            [$client] = $this->newSession($courtId);
            [$y, $m, $d] = $this->splitDate($bsDate);
            $url = $this->reportUrl('daily_public');

            // Step 1: showbench -> list of benches.
            $res = $client->post($url, [
                'form_params' => [
                    'mode' => 'showbench', 'syy' => $y, 'smm' => $m, 'sdd' => $d,
                    'imageField2.x' => 50, 'imageField2.y' => 10,
                ],
                'headers' => ['Referer' => $url],
            ]);
            $html = (string) $res->getBody();
            $benchOptions = $this->parseBenchOptions($html);

            if (empty($benchOptions)) {
                return $this->emptyResult($courtId, 'daily', $bsDate);
            }

            // Step 2: fetch each bench concurrently (shared session).
            $requests = function () use ($url, $benchOptions, $y, $m, $d) {
                foreach ($benchOptions as $opt) {
                    $body = http_build_query([
                        'mode' => 'show', 'bench_type' => $opt['value'],
                        'syy' => $y, 'smm' => $m, 'sdd' => $d,
                        'imageField2.x' => 50, 'imageField2.y' => 10,
                    ]);
                    yield new Request('POST', $url, [
                        'Content-Type' => 'application/x-www-form-urlencoded',
                        'Referer' => $url,
                    ], $body);
                }
            };

            $benchHtml = [];
            $pool = new Pool($client, $requests(), [
                'concurrency' => 5,
                'fulfilled' => function ($response, $index) use (&$benchHtml) {
                    $benchHtml[$index] = (string) $response->getBody();
                },
                'rejected' => function ($reason, $index) use (&$benchHtml) {
                    $benchHtml[$index] = '';
                },
            ]);
            $pool->promise()->wait();

            $benches = [];
            foreach ($benchOptions as $i => $opt) {
                $entries = $this->parseDailyCaseTable($benchHtml[$i] ?? '');
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

            return $this->result($courtId, 'daily', $bsDate, $benches, $url);
        } catch (\Throwable $e) {
            return $this->errorResult($courtId, 'daily', $bsDate, $e);
        }
    }

    // ---------------------------------------------------------------------
    // WEEKLY (loop the six weekdays)
    // ---------------------------------------------------------------------

    public function fetchWeekly(int $courtId, string $bsDate): array
    {
        try {
            [$client] = $this->newSession($courtId);
            [$y, $m, $d] = $this->splitDate($bsDate);
            $ymd = $y . $m . $d;
            $url = $this->reportUrl('weekly_public');

            $requests = function () use ($url, $ymd) {
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

            $dayHtml = [];
            $pool = new Pool($client, $requests(), [
                'concurrency' => 6,
                'fulfilled' => function ($response, $index) use (&$dayHtml) {
                    $dayHtml[$index] = (string) $response->getBody();
                },
                'rejected' => function ($reason, $index) use (&$dayHtml) {
                    $dayHtml[$index] = '';
                },
            ]);
            $pool->promise()->wait();

            $benches = [];
            foreach ($this->weekdays as $day => $label) {
                $entries = $this->parseWeeklyCaseTable($dayHtml[$day] ?? '');
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

            return $this->result($courtId, 'weekly', $bsDate, $benches, $url);
        } catch (\Throwable $e) {
            return $this->errorResult($courtId, 'weekly', $bsDate, $e);
        }
    }

    // ---------------------------------------------------------------------
    // SUPPLEMENTARY (loop the case-type options)
    // ---------------------------------------------------------------------

    public function fetchSupplementary(int $courtId, string $bsDate): array
    {
        try {
            [$client] = $this->newSession($courtId);
            [$y, $m, $d] = $this->splitDate($bsDate);
            $url = $this->reportUrl('weekly_suppli_public');

            $types = array_keys($this->supplementaryTypes);
            $requests = function () use ($url, $y, $m, $d, $types) {
                foreach ($types as $t) {
                    $body = http_build_query([
                        'syy' => $y, 'smm' => $m, 'sdd' => $d,
                        'mode' => 'show', 'yo' => 1, 'causelist' => $t,
                        'imageField.x' => 50, 'imageField.y' => 10,
                    ]);
                    yield $t => new Request('POST', $url, [
                        'Content-Type' => 'application/x-www-form-urlencoded',
                        'Referer' => $url,
                    ], $body);
                }
            };

            $typeHtml = [];
            $pool = new Pool($client, $requests(), [
                'concurrency' => 6,
                'fulfilled' => function ($response, $index) use (&$typeHtml) {
                    $typeHtml[$index] = (string) $response->getBody();
                },
                'rejected' => function ($reason, $index) use (&$typeHtml) {
                    $typeHtml[$index] = '';
                },
            ]);
            $pool->promise()->wait();

            $benches = [];
            foreach ($this->supplementaryTypes as $t => $label) {
                $entries = $this->parseDailyCaseTable($typeHtml[$t] ?? '');
                if (empty($entries)) {
                    continue;
                }
                $benches[] = [
                    'bench_id' => 'suppli_' . $t,
                    'bench_label' => $label,
                    'bench_option' => $label,
                    'judges' => [],
                    'entries' => $entries,
                    'entries_count' => count($entries),
                ];
            }

            return $this->result($courtId, 'supplementary', $bsDate, $benches, $url);
        } catch (\Throwable $e) {
            return $this->errorResult($courtId, 'supplementary', $bsDate, $e);
        }
    }

    // ---------------------------------------------------------------------
    // Parsing helpers
    // ---------------------------------------------------------------------

    /** Extract the bench_type <select> options from the showbench page. */
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
            // no select found
        }
        return $options;
    }

    /**
     * Parse a bench_type option label like "१।।लालबहादुर कुँवर र कविप्रसाद न्यौपाने ,"
     * into a readable bench label ("इजलास १") and the list of judge names.
     */
    private function parseBenchOption(string $text): array
    {
        $text = $this->clean($text);
        $num = '';
        $judgesStr = $text;
        if (str_contains($text, '।।')) {
            [$num, $judgesStr] = array_pad(explode('।।', $text, 2), 2, '');
            $num = trim($num);
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
     * Parse the bench header block ("इजलास नं ...") for a readable label + judges.
     */
    private function parseBenchHeader(string $html): array
    {
        $label = '';
        $judges = [];
        if (trim($html) === '') {
            return ['label' => $label, 'judges' => $judges];
        }
        $crawler = new Crawler($html);
        try {
            $crawler->filter('table')->each(function (Crawler $t) use (&$label, &$judges) {
                if ($label !== '') {
                    return;
                }
                $text = $this->clean($t->text(''));
                if (str_contains($text, 'इजलास नं') && (str_contains($text, 'पेसी') || str_contains($text, 'न्या'))) {
                    $label = $text;
                    // Judges are the "मा.न्या. श्री <name>" / "मा.मु.न्या. श्री <name>" fragments.
                    if (preg_match_all('/मा\.(?:मु\.)?न्या\.\s*श्री\s*([^\n]+?)(?=मा\.|$)/u', $text, $mm)) {
                        foreach ($mm[1] as $j) {
                            $j = trim($j);
                            if ($j !== '') {
                                $judges[] = $j;
                            }
                        }
                    }
                }
            });
        } catch (\Throwable $e) {
            // ignore
        }
        // Keep the label compact.
        if (mb_strlen($label) > 160) {
            $label = mb_substr($label, 0, 160) . '…';
        }
        return ['label' => $label, 'judges' => $judges];
    }

    /**
     * Parse a DAILY case table.
     */
    private function parseDailyCaseTable(string $html): array
    {
        return $this->parseCases($html);
    }

    /**
     * Parse a WEEKLY case table.
     */
    private function parseWeeklyCaseTable(string $html): array
    {
        return $this->parseCases($html);
    }

    /**
     * Content-based case-row parser that works across daily / weekly /
     * supplementary layouts (which differ in column count and, for
     * supplementary, use a legacy Nepali font in the header instead of Unicode
     * <th>). Rather than matching header text, each data row is located by the
     * case-number cell (e.g. "083-CB-0026"); the subject sits immediately
     * before it, the parties immediately after, and any trailing cells become
     * the remarks. The appeal site's malformed HTML nests tables, so a merged
     * "mega row" can appear — those are skipped by the cell-count bounds, and a
     * seen-set dedupes rows that libxml duplicated across nested tables.
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
                    return; // header rows / merged mega-rows
                }
                $cells = [];
                for ($i = 0; $i < $n; $i++) {
                    $cells[$i] = $this->clean($tds->eq($i)->text(''));
                }

                // Locate the case-number cell (first "NNN-XX-NNNN" style value).
                $ci = -1;
                foreach ($cells as $i => $v) {
                    if (preg_match('/\d{2,3}\s*-\s*[A-Za-z]{1,6}\s*-\s*\d+/u', $v)) {
                        $ci = $i;
                        break;
                    }
                }
                if ($ci < 1) {
                    return; // need a subject column before the case number
                }

                $serial = $cells[0] ?? '';
                $caseNumber = $cells[$ci];
                $subject = $cells[$ci - 1] ?? '';
                $parties = $cells[$ci + 1] ?? '';

                $remarkParts = [];
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

    private function result(int $courtId, string $listType, string $bsDate, array $benches, string $url): array
    {
        $names = $this->courtName($courtId);
        $totalCases = array_sum(array_map(fn ($b) => $b['entries_count'], $benches));
        return [
            'success' => true,
            'court_id' => $courtId,
            'court_name' => $names['ne'],
            'court_name_en' => $names['en'],
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

    private function emptyResult(int $courtId, string $listType, string $bsDate): array
    {
        return $this->result($courtId, $listType, $bsDate, [], $this->reportUrl($listType));
    }

    private function errorResult(int $courtId, string $listType, string $bsDate, \Throwable $e): array
    {
        Log::error('High court fetch failed', [
            'court_id' => $courtId, 'list_type' => $listType, 'error' => $e->getMessage(),
        ]);
        return [
            'success' => false,
            'error' => 'Failed to fetch cause list: ' . $e->getMessage(),
            'court_id' => $courtId,
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
