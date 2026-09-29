<?php

namespace NepalCauseList\Tests;

use InvalidArgumentException;
use NepalCauseList\CauseListClient;
use PHPUnit\Framework\TestCase;

class CauseListClientTest extends TestCase
{
    public function testCourtsMetadata(): void
    {
        $client = new CauseListClient();
        $keys = array_column($client->courts(), 'key');
        $this->assertEqualsCanonicalizing(
            ['supremecourt', 'highcourt', 'districtcourt', 'specialcourt', 'consumercourt'],
            $keys
        );
    }

    public function testRejectsUnknownCourt(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CauseListClient())->fetch('municipalcourt', 'daily', '2083-06-12');
    }

    public function testRejectsUnknownListType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CauseListClient())->fetch('supremecourt', 'yearly', '2083-06-12');
    }

    public function testRejectsBadDate(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CauseListClient())->fetch('supremecourt', 'daily', '12-06-2083');
    }

    public function testHighCourtRequiresCourtId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CauseListClient())->fetch('highcourt', 'daily', '2083-06-12');
    }
}
