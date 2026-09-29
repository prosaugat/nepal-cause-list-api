<?php

namespace NepalCauseList\Courts;

use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use Symfony\Component\DomCrawler\Crawler;
use NepalCauseList\Support\Log;

/**
 * Cause list scraper for the Special Court and Consumer Court.
 *
 * Both courts run the same legacy court-CMS as the Supreme Court
 * (identical form parameters + result table markup), but they sit on
 * sub-paths that are NOT behind the F5 WAF, so plain concurrent HTTP works —
 * no Selenium warm-up required.
 *
 * Report flows (POST):
 *   daily         two-step: mode=showbench -> mode=show per bench_type
 *   weekly        one request per weekday (day=0..5)
 *   supplementary a single request (mode=show + date) returns the whole list
 *
 * URL styles differ between the two courts:
 *   special   .../special/syspublic.php?d=reports&f=<flag>
 *   consumer  .../consumercourt/causelist/index.php?flag=<flag>
 */
class CmsCourtService
{
    private string $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    private array $weekdays = [
        0 => 'आइतबार (Sunday)',
        1 => 'सोमबार (Monday)',
        2 => 'मंगलबार (Tuesday)',
        3 => 'बुधबार (Wednesday)',
        4 => 'बिहीबार (Thursday)',
        5 => 'शुक्रबार (Friday)',
    ];

    private array $courts = [
        'specialcourt' => [
            'name' => 'विशेष अदालत',
            'name_en' => 'Special Court',
            'base' => 'https://supremecourt.gov.np/special/syspublic.php',
            'url_style' => 'query', // ?d=reports&f=<flag>
            'flags' => [
                'daily' => 'daily_public',
                'weekly' => 'weekly_public',
                'supplementary' => 'suplementary_public',
            ],
        ],
        'consumercourt' => [
            'name' => 'उपभोक्ता अदालत',
            'name_en' => 'Consumer Court',
            'base' => 'https://supremecourt.gov.np/consumercourt/causelist/index.php',
            'url_style' => 'flag', // ?flag=<flag>
            'flags' => [
                'daily' => 'daily',
                'weekly' => 'weekly',
                'supplementary' => 'suplementary',
            ],
        ],
    ];

    private string $courtKey;
    private array $config;

    public function __construct(string $courtKey)
    {
        if (!isset($this->courts[$courtKey])) {
            throw new \InvalidArgumentException("Unknown CMS court: {$courtKey}");
        }
        $this->courtKey = $courtKey;
        $this->config = $this->courts[$courtKey];
    }

    public static function supportsCourt(string $courtKey): bool
    {
        return in_array($courtKey, ['specialcourt', 'consumercourt'], true);
    }

    private function reportUrl(string $listType): string
    {
        $flag = $this->config['flags'][$listType];
        if ($this->config['url_style'] === 'flag') {
            return $this->config['base'] . '?flag=' . $flag;
        }
        return $this->config['base'] . '?d=reports&f=' . $flag;
    }

    private function splitDate(string $bsDate): array
    {
        [$y, $m, $d] = array_pad(explode('-', $bsDate), 3, '01');
        return [$y, str_pad($m, 2, '0', STR_PAD_LEFT), str_pad($d, 2, '0', STR_PAD_LEFT)];
    }

    private function client(): Client
    {
        return new Client([
            'timeout' => 60,
            'verify' => false,
            'cookies' => true,
            'http_errors' => false,
            'headers' => [
                'User-Agent' => $this->ua,
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.9,ne;q=0.8',
            ],
        ]);
    }

    // ---------------------------------------------------------------------
    // DAILY (two-step: showbench -> show per bench)
    // ---------------------------------------------------------------------

    public function fetchDaily(string $bsDate): array
    {
        try {
            [$y, $m, $d] = $this->splitDate($bsDate);
            $url = $this->reportUrl('daily');
            $client = $this->client();

            // Prime cookies.
            $client->get($url);

            $res = $client->post($url, [
                'form_params' => [
                    'mode' => 'showbench', 'syy' => $y, 'smm' => $m, 'sdd' => $d,
                    'imageField2.x' => 50, 'imageField2.y' => 10,
                ],
                'headers' => ['Referer' => $url],
            ]);
            $benchOptions = $this->parseBenchOptions((string) $res->getBody());

            if (empty($benchOptions)) {
                return $this->result('daily', $bsDate, [], $url);
            }

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
            (new Pool($client, $requests(), [
                'concurrency' => 5,
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
            $url = $this->reportUrl('weekly');
            $client = $this->client();

            $client->get($url);

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
            (new Pool($client, $requests(), [
                'concurrency' => 6,
                'fulfilled' => function ($response, $index) use (&$dayHtml) {
                    $dayHtml[$index] = (string) $response->getBody();
                },
                'rejected' => function ($reason, $index) use (&$dayHtml) {
                    $dayHtml[$index] = '';
                },
            ]))->promise()->wait();

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
    // SUPPLEMENTARY (single request returns the whole list)
    // ---------------------------------------------------------------------

    public function fetchSupplementary(string $bsDate): array
    {
        try {
            [$y, $m, $d] = $this->splitDate($bsDate);
            $url = $this->reportUrl('supplementary');
            $client = $this->client();

            $client->get($url);

            $res = $client->post($url, [
                'form_params' => [
                    'syy' => $y, 'smm' => $m, 'sdd' => $d,
                    'mode' => 'show', 'yo' => 1, 'causelist' => '',
                    'imageField.x' => 50, 'imageField.y' => 10,
                ],
                'headers' => ['Referer' => $url],
            ]);
            $entries = $this->parseCases((string) $res->getBody());

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
    // Parsing helpers (identical layout to the Supreme Court CMS)
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
        if (preg_match('/^([०-९\d]+)\s*[.।]+\s*(.*)$/u', $text, $mm)) {
            $num = trim($mm[1]);
            $judgesStr = $mm[2];
        }
        $judgesStr = trim(rtrim(trim($judgesStr), ','));
        $judges = [];
        foreach (preg_split('/\s+र\s+|,/u', $judgesStr) as $j) {
            $j = trim(rtrim(trim($j), ','));
            if ($j !== '') {
                $judges[] = $j;
            }
        }
        $label = $num !== '' ? ('इजलास ' . $num) : ($judgesStr !== '' ? $judgesStr : 'इजलास');
        return ['label' => $label, 'judges' => $judges];
    }

    /**
     * Content-based case-row parser: each data row is located by its
     * case-number cell; subject sits before it, parties after, trailing
     * cells become remarks.
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
                // cell (e.g. a file-status remark) becomes a remark.
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
            'court_name' => $this->config['name'],
            'court_name_en' => $this->config['name_en'],
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

    private function errorResult(string $listType, string $bsDate, \Throwable $e): array
    {
        Log::error('CMS court fetch failed', [
            'court' => $this->courtKey, 'list_type' => $listType, 'error' => $e->getMessage(),
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
