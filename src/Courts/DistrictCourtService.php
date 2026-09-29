<?php

namespace NepalCauseList\Courts;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Symfony\Component\DomCrawler\Crawler;
use NepalCauseList\Support\Log;

/**
 * District Court cause list scraper.
 *
 * The weekly_dainik system on supremecourt.gov.np is NOT protected by the F5 WAF
 * that guards the main Supreme Court cause list, so plain HTTP (Guzzle) works.
 *
 * Flow: GET the court page to establish a CodeIgniter session + F5 cookies, then
 * POST the date (Nepali digits) which triggers a 302 redirect back to a results
 * page (Post-Redirect-Get). We follow the redirect and parse the tables.
 *
 * Endpoints (per court id):
 *   daily            -> pesi/daily/{id}              POST pesi_date + todays_date
 *   weekly           -> pesi/weekly_pesi/{id}        POST pesi_bar
 *   supplementary    -> pesi/supplementary_pesi/{id} POST pesi_date
 */
class DistrictCourtService
{
    private string $baseUrl = 'https://supremecourt.gov.np/weekly_dainik/pesi';

    /**
     * Complete map of district court id => [nepali name, english name].
     * Ids come from https://supremecourt.gov.np/weekly_dainik/home
     */
    private array $courts = [
        18 => ['ne' => 'झापा जिल्ला अदालत', 'en' => 'Jhapa District Court'],
        19 => ['ne' => 'इलाम जिल्ला अदालत', 'en' => 'Ilam District Court'],
        20 => ['ne' => 'ताप्लेजुङ जिल्ला अदालत', 'en' => 'Taplejung District Court'],
        21 => ['ne' => 'पाँचथर जिल्ला अदालत', 'en' => 'Panchthar District Court'],
        22 => ['ne' => 'तेह्रथुम जिल्ला अदालत', 'en' => 'Terhathum District Court'],
        23 => ['ne' => 'संखुवासभा जिल्ला अदालत', 'en' => 'Sankhuwasabha District Court'],
        24 => ['ne' => 'भोजपुर जिल्ला अदालत', 'en' => 'Bhojpur District Court'],
        25 => ['ne' => 'धनकुटा जिल्ला अदालत', 'en' => 'Dhankuta District Court'],
        26 => ['ne' => 'सुनसरी जिल्ला अदालत', 'en' => 'Sunsari District Court'],
        27 => ['ne' => 'मोरङ जिल्ला अदालत', 'en' => 'Morang District Court'],
        28 => ['ne' => 'सोलुखुम्बु जिल्ला अदालत', 'en' => 'Solukhumbu District Court'],
        29 => ['ne' => 'ओखलढुंगा जिल्ला अदालत', 'en' => 'Okhaldhunga District Court'],
        30 => ['ne' => 'खोटांङ जिल्ला अदालत', 'en' => 'Khotang District Court'],
        31 => ['ne' => 'उदयपुर जिल्ला अदालत', 'en' => 'Udayapur District Court'],
        32 => ['ne' => 'सिराहा जिल्ला अदालत', 'en' => 'Siraha District Court'],
        33 => ['ne' => 'सप्तरी जिल्ला अदालत', 'en' => 'Saptari District Court'],
        34 => ['ne' => 'रामेछाप जिल्ला अदालत', 'en' => 'Ramechhap District Court'],
        35 => ['ne' => 'सिन्धुली जिल्ला अदालत', 'en' => 'Sindhuli District Court'],
        36 => ['ne' => 'धनुषा जिल्ला अदालत', 'en' => 'Dhanusha District Court'],
        37 => ['ne' => 'महोत्तरी जिल्ला अदालत', 'en' => 'Mahottari District Court'],
        38 => ['ne' => 'सर्लाही जिल्ला अदालत', 'en' => 'Sarlahi District Court'],
        39 => ['ne' => 'काठमाडौं जिल्ला अदालत', 'en' => 'Kathmandu District Court'],
        40 => ['ne' => 'ललितपुर जिल्ला अदालत', 'en' => 'Lalitpur District Court'],
        41 => ['ne' => 'भक्तपुर जिल्ला अदालत', 'en' => 'Bhaktapur District Court'],
        42 => ['ne' => 'दोलखा जिल्ला अदालत', 'en' => 'Dolakha District Court'],
        43 => ['ne' => 'सिन्धुपाल्चोक जिल्ला अदालत', 'en' => 'Sindhupalchok District Court'],
        44 => ['ne' => 'काभ्रेपलान्चोक जिल्ला अदालत', 'en' => 'Kavrepalanchok District Court'],
        45 => ['ne' => 'रसुवा जिल्ला अदालत', 'en' => 'Rasuwa District Court'],
        46 => ['ne' => 'धादिङ जिल्ला अदालत', 'en' => 'Dhading District Court'],
        47 => ['ne' => 'नुवाकोट जिल्ला अदालत', 'en' => 'Nuwakot District Court'],
        48 => ['ne' => 'मकवानपुर जिल्ला अदालत', 'en' => 'Makwanpur District Court'],
        49 => ['ne' => 'चितवन जिल्ला अदालत', 'en' => 'Chitwan District Court'],
        50 => ['ne' => 'बारा जिल्ला अदालत', 'en' => 'Bara District Court'],
        51 => ['ne' => 'पर्सा जिल्ला अदालत', 'en' => 'Parsa District Court'],
        52 => ['ne' => 'रौतहट जिल्ला अदालत', 'en' => 'Rautahat District Court'],
        53 => ['ne' => 'मनांग जिल्ला अदालत', 'en' => 'Manang District Court'],
        54 => ['ne' => 'गोरखा जिल्ला अदालत', 'en' => 'Gorkha District Court'],
        55 => ['ne' => 'लमजुंग जिल्ला अदालत', 'en' => 'Lamjung District Court'],
        56 => ['ne' => 'तनहुँ जिल्ला अदालत', 'en' => 'Tanahun District Court'],
        57 => ['ne' => 'कास्की जिल्ला अदालत', 'en' => 'Kaski District Court'],
        58 => ['ne' => 'स्याङ्जा जिल्ला अदालत', 'en' => 'Syangja District Court'],
        59 => ['ne' => 'मुस्तांग जिल्ला अदालत', 'en' => 'Mustang District Court'],
        60 => ['ne' => 'म्याग्दी जिल्ला अदालत', 'en' => 'Myagdi District Court'],
        61 => ['ne' => 'पर्वत जिल्ला अदालत', 'en' => 'Parbat District Court'],
        62 => ['ne' => 'बागलुङ जिल्ला अदालत', 'en' => 'Baglung District Court'],
        63 => ['ne' => 'गुल्मी जिल्ला अदालत', 'en' => 'Gulmi District Court'],
        64 => ['ne' => 'अर्घाखाँची जिल्ला अदालत', 'en' => 'Arghakhanchi District Court'],
        65 => ['ne' => 'पाल्पा जिल्ला अदालत', 'en' => 'Palpa District Court'],
        66 => ['ne' => 'नवलपरासी जिल्ला अदालत', 'en' => 'Nawalparasi District Court'],
        67 => ['ne' => 'रूपन्देही जिल्ला अदालत', 'en' => 'Rupandehi District Court'],
        68 => ['ne' => 'कपिलवस्तु जिल्ला अदालत', 'en' => 'Kapilvastu District Court'],
        69 => ['ne' => 'रुकुम जिल्ला अदालत', 'en' => 'Rukum District Court'],
        70 => ['ne' => 'रोल्पा जिल्ला अदालत', 'en' => 'Rolpa District Court'],
        71 => ['ne' => 'सल्यान जिल्ला अदालत', 'en' => 'Salyan District Court'],
        72 => ['ne' => 'प्युठान जिल्ला अदालत', 'en' => 'Pyuthan District Court'],
        73 => ['ne' => 'दाङ जिल्ला अदालत', 'en' => 'Dang District Court'],
        74 => ['ne' => 'बाँके जिल्ला अदालत', 'en' => 'Banke District Court'],
        75 => ['ne' => 'बर्दिया जिल्ला अदालत', 'en' => 'Bardiya District Court'],
        76 => ['ne' => 'जाजरकोट जिल्ला अदालत', 'en' => 'Jajarkot District Court'],
        77 => ['ne' => 'दैलेख जिल्ला अदालत', 'en' => 'Dailekh District Court'],
        78 => ['ne' => 'सुर्खेत जिल्ला अदालत', 'en' => 'Surkhet District Court'],
        79 => ['ne' => 'जुम्ला जिल्ला अदालत', 'en' => 'Jumla District Court'],
        80 => ['ne' => 'हुम्ला जिल्ला अदालत', 'en' => 'Humla District Court'],
        81 => ['ne' => 'डोल्पा जिल्ला अदालत', 'en' => 'Dolpa District Court'],
        82 => ['ne' => 'मुगु जिल्ला अदालत', 'en' => 'Mugu District Court'],
        83 => ['ne' => 'कालिकोट जिल्ला अदालत', 'en' => 'Kalikot District Court'],
        84 => ['ne' => 'डोटी जिल्ला अदालत', 'en' => 'Doti District Court'],
        85 => ['ne' => 'कैलाली जिल्ला अदालत', 'en' => 'Kailali District Court'],
        86 => ['ne' => 'अछाम जिल्ला अदालत', 'en' => 'Achham District Court'],
        87 => ['ne' => 'बाजुरा जिल्ला अदालत', 'en' => 'Bajura District Court'],
        88 => ['ne' => 'बझाङ जिल्ला अदालत', 'en' => 'Bajhang District Court'],
        89 => ['ne' => 'दार्चुला जिल्ला अदालत', 'en' => 'Darchula District Court'],
        90 => ['ne' => 'बैतडी जिल्ला अदालत', 'en' => 'Baitadi District Court'],
        91 => ['ne' => 'डडेलधुरा जिल्ला अदालत', 'en' => 'Dadeldhura District Court'],
        92 => ['ne' => 'कञ्चनपुर जिल्ला अदालत', 'en' => 'Kanchanpur District Court'],
        95 => ['ne' => 'नवलपुर जिल्ला अदालत', 'en' => 'Nawalpur District Court'],
        96 => ['ne' => 'रुकुमकोट जिल्ला अदालत', 'en' => 'Rukumkot District Court'],
    ];

    /**
     * Return the full list of courts for the frontend dropdown.
     * @return array<int, array{id:int, name_ne:string, name_en:string}>
     */
    public function getCourts(): array
    {
        $out = [];
        foreach ($this->courts as $id => $names) {
            $out[] = [
                'id' => $id,
                'name_ne' => $names['ne'],
                'name_en' => $names['en'],
            ];
        }
        return $out;
    }

    public function hasCourt(int $id): bool
    {
        return isset($this->courts[$id]);
    }

    public function courtName(int $id): array
    {
        return $this->courts[$id] ?? ['ne' => "District Court {$id}", 'en' => "District Court {$id}"];
    }

    private function makeClient(): array
    {
        $jar = new CookieJar();
        $client = new Client([
            'timeout' => 45,
            'verify' => false,
            'cookies' => $jar,
            'allow_redirects' => ['max' => 5, 'referer' => true],
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.9,ne;q=0.8',
            ],
        ]);
        return [$client, $jar];
    }

    /**
     * Convert an English-digit string (e.g. a BS date "2083-06-12") to Nepali digits.
     */
    public function toNepaliDigits(string $text): string
    {
        $map = ['0' => '०', '1' => '१', '2' => '२', '3' => '३', '4' => '४',
                '5' => '५', '6' => '६', '7' => '७', '8' => '८', '9' => '९'];
        return strtr($text, $map);
    }

    /**
     * Convert Nepali digits back to English digits.
     */
    public function toEnglishDigits(string $text): string
    {
        $map = ['०' => '0', '१' => '1', '२' => '2', '३' => '3', '४' => '4',
                '५' => '5', '६' => '6', '७' => '7', '८' => '8', '९' => '9'];
        return strtr($text, $map);
    }

    /**
     * Fetch the daily cause list for a district court.
     *
     * @param int    $districtId District court id
     * @param string $bsDate     BS date in Y-m-d (English digits), e.g. 2083-06-12
     */
    public function fetchDaily(int $districtId, string $bsDate): array
    {
        $endpoint = "{$this->baseUrl}/daily/{$districtId}";
        $nepDate = $this->toNepaliDigits($bsDate);

        return $this->fetchAndParse($districtId, $endpoint, [
            'todays_date' => $bsDate,
            'pesi_date'   => $nepDate,
            'submit'      => 'खोज्नु होस्',
        ], 'daily', $bsDate);
    }

    /**
     * Fetch the weekly cause list. `pesi_bar=all` returns every day of the week.
     */
    public function fetchWeekly(int $districtId, string $bsDate): array
    {
        $endpoint = "{$this->baseUrl}/weekly_pesi/{$districtId}";

        return $this->fetchAndParse($districtId, $endpoint, [
            'pesi_bar' => 'all',
            'submit'   => 'खोज्नु होस्',
        ], 'weekly', $bsDate);
    }

    /**
     * Fetch the supplementary (पूरक) cause list for a date.
     */
    public function fetchSupplementary(int $districtId, string $bsDate): array
    {
        $endpoint = "{$this->baseUrl}/supplementary_pesi/{$districtId}";
        $nepDate = $this->toNepaliDigits($bsDate);

        return $this->fetchAndParse($districtId, $endpoint, [
            'pesi_date' => $nepDate,
            'submit'    => 'खोज्नु होस्',
        ], 'supplementary', $bsDate);
    }

    /**
     * Shared: establish session, POST the form, follow redirect, parse the result.
     */
    private function fetchAndParse(int $districtId, string $endpoint, array $formData, string $listType, string $bsDate): array
    {
        try {
            [$client, $jar] = $this->makeClient();

            // Step 1: GET the daily page to establish session + WAF cookies.
            $client->get("{$this->baseUrl}/daily/{$districtId}");

            // Step 2: POST the form. Guzzle follows the 302 back to the results page.
            $response = $client->post($endpoint, [
                'form_params' => $formData,
                'headers' => [
                    'Referer' => "{$this->baseUrl}/daily/{$districtId}",
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
            ]);

            $html = $response->getBody()->getContents();

            if (strlen(trim($html)) < 100) {
                return [
                    'success' => false,
                    'error' => 'No data returned for the selected date.',
                    'court_id' => $districtId,
                    'date' => $bsDate,
                ];
            }

            $benches = match ($listType) {
                'daily' => $this->parseDaily($html),
                'weekly' => $this->parseDateGrouped($html),
                'supplementary' => $this->parseDateGrouped($html),
                default => [],
            };

            $totalCases = array_sum(array_map(fn ($b) => $b['entries_count'], $benches));
            $names = $this->courtName($districtId);

            return [
                'success' => true,
                'court_id' => $districtId,
                'court_name' => $names['ne'],
                'court_name_en' => $names['en'],
                'list_type' => $listType,
                'date' => $bsDate,
                'fetched_at' => date('c'),
                'source_url' => $endpoint,
                'total_benches' => count($benches),
                'total_cases' => $totalCases,
                'bench_options' => array_map(fn ($b) => [
                    'value' => $b['bench_id'],
                    'text' => $b['bench_label'],
                ], $benches),
                'benches' => $benches,
            ];
        } catch (\Throwable $e) {
            Log::error('District court fetch failed', [
                'district_id' => $districtId,
                'list_type' => $listType,
                'error' => $e->getMessage(),
            ]);
            return [
                'success' => false,
                'error' => 'Failed to fetch cause list: ' . $e->getMessage(),
                'court_id' => $districtId,
                'date' => $bsDate,
            ];
        }
    }

    /**
     * Parse the DAILY layout: alternating bench-header tables and record_display
     * tables. Consecutive records for the same bench number are merged.
     *
     * Daily record columns (10):
     *   0 serial, 1 case_no, 2 reg_date, 3 subject, 4 plaintiff, 5 defendant,
     *   6 faant, 7 priority, 8 remarks, 9 order_type
     */
    private function parseDaily(string $html): array
    {
        $crawler = new Crawler($html);
        $benches = [];
        $current = null; // bench label + judge waiting for its record table

        $crawler->filter('table')->each(function (Crawler $table) use (&$benches, &$current) {
            $class = $table->attr('class') ?? '';
            $text = $this->clean($table->text(''));

            // Record table -> attach to the pending bench.
            if (str_contains($class, 'record_display')) {
                $benchNo = $current['no'] ?? '';
                $benchLabel = $current['label'] ?? 'इजलाश';
                $judges = $current['judges'] ?? [];

                $entries = $this->parseDailyRows($table);
                if (empty($entries)) {
                    return;
                }

                // Merge with previous bench if it's the same bench number.
                $last = count($benches) - 1;
                if ($last >= 0 && $benchNo !== '' && ($benches[$last]['_bench_no'] ?? null) === $benchNo) {
                    $benches[$last]['entries'] = array_merge($benches[$last]['entries'], $entries);
                    $benches[$last]['entries_count'] = count($benches[$last]['entries']);
                    return;
                }

                $benches[] = [
                    'bench_id' => 'bench_' . (count($benches) + 1),
                    '_bench_no' => $benchNo,
                    'bench_label' => $benchLabel,
                    'bench_option' => $benchLabel,
                    'judges' => $judges,
                    'entries' => $entries,
                    'entries_count' => count($entries),
                ];
                return;
            }

            // Bench header table -> contains "इजलाश N" and a judge name.
            if (preg_match('/इजलाश\s*([\d०-९]+)/u', $text, $m)) {
                $judge = '';
                try {
                    $judge = $this->clean($table->filter('.judge')->text(''));
                } catch (\Throwable $e) {
                    // fall back to full text minus the bench label
                }
                $benchNo = $this->clean($m[1]);
                $current = [
                    'no' => $benchNo,
                    'label' => 'इजलाश ' . $benchNo . ($judge !== '' ? ' - ' . $judge : ''),
                    'judges' => $judge !== '' ? [$judge] : [],
                ];
            }
        });

        // Strip internal helper key.
        foreach ($benches as &$b) {
            unset($b['_bench_no']);
        }
        return $benches;
    }

    /**
     * Parse rows of a DAILY record_display table into normalized entries.
     */
    private function parseDailyRows(Crawler $table): array
    {
        $entries = [];
        $table->filter('tr')->each(function (Crawler $row) use (&$entries) {
            // Skip the header row (contains th).
            if ($row->filter('th')->count() > 0) {
                return;
            }
            $cells = $row->filter('td');
            if ($cells->count() < 6) {
                return;
            }

            $caseNumber = $this->clean($cells->eq(1)->text(''));
            $subject = $this->clean($cells->eq(3)->text(''));
            $plaintiff = $this->clean($cells->eq(4)->text(''));
            $defendant = $this->clean($cells->eq(5)->text(''));
            $priority = $cells->count() > 7 ? $this->clean($cells->eq(7)->text('')) : '';
            $remarks = $cells->count() > 8 ? $this->clean($cells->eq(8)->text('')) : '';
            $orderType = $cells->count() > 9 ? $this->clean($cells->eq(9)->text('')) : '';

            $parties = trim($plaintiff . ($defendant !== '' ? ' वि. ' . $defendant : ''));
            $remarksCombined = trim(implode(' | ', array_filter([$priority, $remarks, $orderType])));

            if ($caseNumber === '' && $subject === '' && $parties === '') {
                return;
            }

            $entries[] = [
                'serial' => $this->clean($cells->eq(0)->text('')),
                'case_number' => $caseNumber,
                'reg_date' => $this->clean($cells->eq(2)->text('')),
                'subject' => $subject,
                'parties' => $parties,
                'remarks' => $remarksCombined,
            ];
        });
        return $entries;
    }

    /**
     * Parse the WEEKLY / SUPPLEMENTARY layout. Each record_display table is a
     * block preceded by a "पेशी मिति :<date>" heading. Columns (8):
     *   0 serial, 1 case_no, 2 reg_date, 3 subject, 4 parties(पक्ष||विपक्ष),
     *   5 faant, 6 priority, 7 remarks
     * We treat each date block as a "bench" so the frontend can render it.
     */
    private function parseDateGrouped(string $html): array
    {
        $crawler = new Crawler($html);
        $benches = [];

        $crawler->filter('table.record_display')->each(function (Crawler $table) use (&$benches, $crawler) {
            $entries = $this->parseDateGroupedRows($table);
            if (empty($entries)) {
                return;
            }

            // Find the nearest preceding "पेशी मिति :date" heading.
            $label = 'पेशी सूची';
            $full = $this->clean($crawler->text(''));
            // The date heading sits just before the table content in document text.
            // Extract from the table's own preceding sibling context via the whole html.
            $label = $this->findDateHeadingBefore($crawler, $table) ?? $label;

            $benches[] = [
                'bench_id' => 'block_' . (count($benches) + 1),
                'bench_label' => $label,
                'bench_option' => $label,
                'judges' => [],
                'entries' => $entries,
                'entries_count' => count($entries),
            ];
        });

        return $benches;
    }

    /**
     * Locate the "पेशी मिति :<date>" heading that precedes a given record table.
     */
    private function findDateHeadingBefore(Crawler $root, Crawler $table): ?string
    {
        // Walk up to find an ancestor row, then read h3.style1 text within the block.
        try {
            $node = $table->getNode(0);
            // climb ancestors looking for a heading sibling
            $ancestor = $node;
            for ($i = 0; $i < 6 && $ancestor !== null; $i++) {
                $prev = $ancestor->previousSibling;
                while ($prev !== null) {
                    if ($prev->nodeType === XML_ELEMENT_NODE) {
                        $frag = new Crawler($prev);
                        $h = '';
                        try {
                            $h = $this->clean($frag->filter('.style1')->text(''));
                        } catch (\Throwable $e) {
                            $h = '';
                        }
                        if ($h === '') {
                            $t = $this->clean($frag->text(''));
                            if (str_contains($t, 'पेशी मिति')) {
                                $h = $t;
                            }
                        }
                        if ($h !== '' && str_contains($h, 'पेशी मिति')) {
                            return trim($h);
                        }
                    }
                    $prev = $prev->previousSibling;
                }
                $ancestor = $ancestor->parentNode;
            }
        } catch (\Throwable $e) {
            // ignore, fall through
        }
        return null;
    }

    /**
     * Parse rows of a WEEKLY/SUPPLEMENTARY record_display table.
     */
    private function parseDateGroupedRows(Crawler $table): array
    {
        $entries = [];
        $table->filter('tr')->each(function (Crawler $row) use (&$entries) {
            if ($row->filter('th')->count() > 0) {
                return;
            }
            $cells = $row->filter('td');
            if ($cells->count() < 5) {
                return;
            }

            $caseNumber = $this->clean($cells->eq(1)->text(''));
            $subject = $this->clean($cells->eq(3)->text(''));
            $parties = $this->clean($cells->eq(4)->text(''));

            // Weekly has an extra "संकेत" column vs supplementary, so read
            // priority/remarks from the trailing columns to stay robust.
            $n = $cells->count();
            $priority = $n >= 3 ? $this->clean($cells->eq($n - 2)->text('')) : '';
            $remarks = $n >= 2 ? $this->clean($cells->eq($n - 1)->text('')) : '';

            $remarksCombined = trim(implode(' | ', array_filter([$priority, $remarks])));

            if ($caseNumber === '' && $subject === '' && $parties === '') {
                return;
            }

            $entries[] = [
                'serial' => $this->clean($cells->eq(0)->text('')),
                'case_number' => $caseNumber,
                'reg_date' => $this->clean($cells->eq(2)->text('')),
                'subject' => $subject,
                'parties' => $parties,
                'remarks' => $remarksCombined,
            ];
        });
        return $entries;
    }

    /**
     * Normalize whitespace and strip &nbsp; artifacts.
     */
    private function clean(string $text): string
    {
        $text = str_replace(["\xc2\xa0", '&nbsp;'], ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim($text);
    }
}
