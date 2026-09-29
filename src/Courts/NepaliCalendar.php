<?php

namespace NepalCauseList\Courts;

class NepaliCalendar
{
    /**
     * Accurate day-count per BS month, indexed by BS year.
     * Each row is Baishakh (index 0) .. Chaitra (index 11).
     * Anchor: 2080-01-01 BS = 2023-04-14 AD. Dates outside this range fall
     * back to 365-day years and drift slightly — extend this table to widen
     * the accurate window.
     */
    private const BS_DAYS_IN_MONTH = [
        2080 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30], // 365 days
        2081 => [31, 31, 32, 32, 31, 30, 30, 29, 30, 29, 30, 30], // 365 days
        2082 => [31, 32, 31, 32, 31, 30, 30, 30, 29, 29, 30, 31], // 366 days
        2083 => [31, 31, 31, 32, 31, 31, 29, 30, 30, 29, 30, 30], // 365 days
        2084 => [31, 31, 32, 31, 31, 31, 30, 29, 30, 29, 30, 30], // 365 days
    ];

    /**
     * Get current Nepali (BS) date
     */
    public function getCurrentBSDate(): array
    {
        // Get current AD date
        $adYear = (int)date('Y');
        $adMonth = (int)date('m');
        $adDay = (int)date('d');
        
        return $this->convertADToBS($adYear, $adMonth, $adDay);
    }
    
    /**
     * Convert AD date to BS date using accurate Nepali calendar.
     *
     * Uses the same anchor as convertBSToAD (2080-01-01 BS = 2023-04-14 AD)
     * and the accurate month-length table, so the two conversions round-trip
     * for any date covered by BS_DAYS_IN_MONTH. Outside that range it falls
     * back to 365-day years, which drifts a little — extend the table to widen
     * the accurate window.
     */
    public function convertADToBS(int $adYear, int $adMonth, int $adDay): array
    {
        $bsDaysInMonth = self::BS_DAYS_IN_MONTH;

        // Reference: 2080-01-01 BS = 2023-04-14 AD
        $baseBS = ['year' => 2080, 'month' => 1, 'day' => 1];
        $baseAD = ['year' => 2023, 'month' => 4, 'day' => 14];

        // Days between the AD anchor and the target AD date.
        $daysToAdd = $this->daysBetweenAD($baseAD, ['year' => $adYear, 'month' => $adMonth, 'day' => $adDay]);

        $bsYear = $baseBS['year'];
        $bsMonth = $baseBS['month'];
        $bsDay = $baseBS['day'];

        if ($daysToAdd >= 0) {
            // Walk forward from the anchor.
            while ($daysToAdd > 0) {
                $daysInMonth = $bsDaysInMonth[$bsYear][$bsMonth - 1] ?? 30;
                $daysLeftInMonth = $daysInMonth - $bsDay;

                if ($daysToAdd <= $daysLeftInMonth) {
                    $bsDay += $daysToAdd;
                    $daysToAdd = 0;
                } else {
                    $daysToAdd -= ($daysLeftInMonth + 1);
                    $bsMonth++;
                    $bsDay = 1;
                    if ($bsMonth > 12) {
                        $bsMonth = 1;
                        $bsYear++;
                    }
                }
            }
        } else {
            // Walk backward from the anchor for dates before it.
            $daysToSubtract = -$daysToAdd;
            while ($daysToSubtract > 0) {
                if ($bsDay > 1) {
                    $step = min($daysToSubtract, $bsDay - 1);
                    $bsDay -= $step;
                    $daysToSubtract -= $step;
                } else {
                    // Move to the last day of the previous month.
                    $bsMonth--;
                    if ($bsMonth < 1) {
                        $bsMonth = 12;
                        $bsYear--;
                    }
                    $bsDay = $bsDaysInMonth[$bsYear][$bsMonth - 1] ?? 30;
                    $daysToSubtract--;
                }
            }
        }

        $formatted = sprintf('%04d-%02d-%02d', $bsYear, $bsMonth, $bsDay);

        return [
            'year' => $bsYear,
            'month' => $bsMonth,
            'day' => $bsDay,
            'formatted' => $formatted,
            'nepali' => $this->toNepaliNumerals($formatted),
        ];
    }
    
    /**
     * Convert BS date to AD date
     */
    public function convertBSToAD(int $bsYear, int $bsMonth, int $bsDay): array
    {
        // BS month days
        $bsDaysInMonth = self::BS_DAYS_IN_MONTH;
        
        // Reference: 2080-01-01 BS = 2023-04-14 AD
        $baseBS = ['year' => 2080, 'month' => 1, 'day' => 1];
        $baseAD = ['year' => 2023, 'month' => 4, 'day' => 14];
        
        // Calculate days from base BS to target BS
        $totalDays = 0;
        
        // Add days for complete years
        for ($y = $baseBS['year']; $y < $bsYear; $y++) {
            if (isset($bsDaysInMonth[$y])) {
                $totalDays += array_sum($bsDaysInMonth[$y]);
            } else {
                $totalDays += 365;
            }
        }
        
        // Add days for complete months in target year
        if (isset($bsDaysInMonth[$bsYear])) {
            for ($m = 0; $m < $bsMonth - 1; $m++) {
                $totalDays += $bsDaysInMonth[$bsYear][$m];
            }
        }
        
        // Add remaining days
        $totalDays += $bsDay - 1;
        
        // Convert to AD by adding days to base AD
        $timestamp = mktime(0, 0, 0, $baseAD['month'], $baseAD['day'] + $totalDays, $baseAD['year']);
        
        return [
            'year' => (int)date('Y', $timestamp),
            'month' => (int)date('m', $timestamp),
            'day' => (int)date('d', $timestamp),
            'formatted' => date('Y-m-d', $timestamp)
        ];
    }
    
    /**
     * Calculate days between two AD dates
     */
    private function daysBetweenAD(array $date1, array $date2): int
    {
        $timestamp1 = mktime(0, 0, 0, $date1['month'], $date1['day'], $date1['year']);
        $timestamp2 = mktime(0, 0, 0, $date2['month'], $date2['day'], $date2['year']);
        
        return (int)(($timestamp2 - $timestamp1) / 86400);
    }
    
    /**
     * Convert to Nepali numerals
     */
    private function toNepaliNumerals(string $text): string
    {
        $englishNumerals = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
        $nepaliNumerals = ['०', '१', '२', '३', '४', '५', '६', '७', '८', '९'];
        
        return str_replace($englishNumerals, $nepaliNumerals, $text);
    }
    
    /**
     * Get BS date from string
     */
    public function parseBSDate(string $bsDateString): array
    {
        list($year, $month, $day) = explode('-', $bsDateString);
        return [
            'year' => (int)$year,
            'month' => (int)$month,
            'day' => (int)$day,
            'formatted' => $bsDateString
        ];
    }
}
