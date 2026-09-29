<?php

namespace NepalCauseList\Tests;

use NepalCauseList\Courts\NepaliCalendar;
use PHPUnit\Framework\TestCase;

class NepaliCalendarTest extends TestCase
{
    public function testBsToAdAnchor(): void
    {
        $cal = new NepaliCalendar();
        // Anchor used by the converter: 2080-01-01 BS = 2023-04-14 AD.
        $ad = $cal->convertBSToAD(2080, 1, 1);
        $this->assertSame('2023-04-14', $ad['formatted']);
    }

    public function testAdToBsRoundTrip(): void
    {
        $cal = new NepaliCalendar();
        $bs = $cal->convertADToBS(2023, 4, 14);
        $this->assertSame(2080, $bs['year']);
        $this->assertSame(1, $bs['month']);
        $this->assertSame(1, $bs['day']);
    }

    public function testParseBsDate(): void
    {
        $cal = new NepaliCalendar();
        $parsed = $cal->parseBSDate('2083-06-12');
        $this->assertSame(2083, $parsed['year']);
        $this->assertSame(6, $parsed['month']);
        $this->assertSame(12, $parsed['day']);
    }
}
