<?php

namespace NepalCauseList;

use InvalidArgumentException;
use NepalCauseList\Courts\CmsCourtService;
use NepalCauseList\Courts\DistrictCourtService;
use NepalCauseList\Courts\HighCourtService;
use NepalCauseList\Courts\NepaliCalendar;
use NepalCauseList\Courts\SupremeCourtService;

/**
 * Unified entry point for every Nepal court cause list.
 *
 * Supported courts:
 *   supremecourt   सर्वोच्च अदालत       (no court id)
 *   highcourt      उच्च अदालत           (needs court id — see highCourts())
 *   districtcourt  जिल्ला अदालत         (needs court id — see districtCourts())
 *   specialcourt   विशेष अदालत          (no court id)
 *   consumercourt  उपभोक्ता अदालत       (no court id)
 *
 * Supported list types: daily, weekly, supplementary.
 * Dates are Bikram Sambat (BS), formatted YYYY-MM-DD, e.g. 2083-06-12.
 */
class CauseListClient
{
    public const LIST_TYPES = ['daily', 'weekly', 'supplementary'];

    public const COURTS = [
        'supremecourt' => ['name' => 'सर्वोच्च अदालत', 'name_en' => 'Supreme Court', 'needs_court_id' => false],
        'highcourt' => ['name' => 'उच्च अदालत', 'name_en' => 'High Court', 'needs_court_id' => true],
        'districtcourt' => ['name' => 'जिल्ला अदालत', 'name_en' => 'District Court', 'needs_court_id' => true],
        'specialcourt' => ['name' => 'विशेष अदालत', 'name_en' => 'Special Court', 'needs_court_id' => false],
        'consumercourt' => ['name' => 'उपभोक्ता अदालत', 'name_en' => 'Consumer Court', 'needs_court_id' => false],
    ];

    private HighCourtService $high;
    private DistrictCourtService $district;
    private SupremeCourtService $supreme;
    private NepaliCalendar $calendar;

    public function __construct()
    {
        $this->high = new HighCourtService();
        $this->district = new DistrictCourtService();
        $this->supreme = new SupremeCourtService();
        $this->calendar = new NepaliCalendar();
    }

    /** List the supported courts and whether each needs a court id. */
    public function courts(): array
    {
        $out = [];
        foreach (self::COURTS as $key => $meta) {
            $out[] = ['key' => $key] + $meta;
        }
        return $out;
    }

    /** Directory of high courts (id + names) for the court-id parameter. */
    public function highCourts(): array
    {
        return $this->high->getCourts();
    }

    /** Directory of district courts (id + names) for the court-id parameter. */
    public function districtCourts(): array
    {
        return $this->district->getCourts();
    }

    /** Today's date in BS as an array {year, month, day, formatted}. */
    public function today(): array
    {
        return $this->calendar->getCurrentBSDate();
    }

    public function nepaliCalendar(): NepaliCalendar
    {
        return $this->calendar;
    }

    /**
     * Fetch a cause list.
     *
     * @param string      $court    one of self::COURTS keys
     * @param string      $listType daily|weekly|supplementary
     * @param string      $bsDate   BS date YYYY-MM-DD (defaults to today)
     * @param int|null    $courtId  required for highcourt / districtcourt
     */
    public function fetch(string $court, string $listType, ?string $bsDate = null, ?int $courtId = null): array
    {
        if (!isset(self::COURTS[$court])) {
            throw new InvalidArgumentException("Unknown court: {$court}");
        }
        if (!in_array($listType, self::LIST_TYPES, true)) {
            throw new InvalidArgumentException("Unknown list type: {$listType}");
        }

        $bsDate = $bsDate ?: $this->today()['formatted'];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $bsDate)) {
            throw new InvalidArgumentException("Invalid BS date (want YYYY-MM-DD): {$bsDate}");
        }

        if (self::COURTS[$court]['needs_court_id'] && $courtId === null) {
            throw new InvalidArgumentException("The {$court} requires a court id.");
        }

        switch ($court) {
            case 'supremecourt':
                return $this->supreme->{'fetch' . ucfirst($listType)}($bsDate);

            case 'highcourt':
                return $this->high->{'fetch' . ucfirst($listType)}($courtId, $bsDate);

            case 'districtcourt':
                return $this->district->{'fetch' . ucfirst($listType)}($courtId, $bsDate);

            case 'specialcourt':
            case 'consumercourt':
                $svc = new CmsCourtService($court);
                return $svc->{'fetch' . ucfirst($listType)}($bsDate);
        }

        throw new InvalidArgumentException("Unhandled court: {$court}");
    }
}
