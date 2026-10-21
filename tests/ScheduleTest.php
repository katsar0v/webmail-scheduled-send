<?php

namespace ScheduledSend\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ScheduledSend\InvalidInput;
use ScheduledSend\Schedule;

class ScheduleTest extends TestCase
{
    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-21 12:00:00 UTC');
    }

    public function testConvertsLocalTimeToUtc(): void
    {
        $utc = Schedule::parse('2026-10-22T08:00', 'Europe/Berlin', $this->now());

        // Berlin is UTC+2 until the end of October
        $this->assertSame('2026-10-22 06:00:00', $utc->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $utc->getTimezone()->getName());
    }

    public function testHandlesTheDstChange(): void
    {
        // After the switch to winter time Berlin is UTC+1
        $utc = Schedule::parse('2026-10-26T08:00', 'Europe/Berlin', $this->now());

        $this->assertSame('2026-10-26 07:00:00', $utc->format('Y-m-d H:i:s'));
    }

    public function testRepeatedHourUsesTheFirstOccurrence(): void
    {
        // 02:30 exists twice on 2026-10-25 in Berlin: 00:30 UTC (CEST) and 01:30 UTC (CET)
        $this->assertSame('2026-10-25 00:30:00', Schedule::parse('2026-10-25T02:30', 'Europe/Berlin', $this->now())->format('Y-m-d H:i:s'));
        // Same in Sofia, one hour later locally
        $this->assertSame('2026-10-25 00:30:00', Schedule::parse('2026-10-25T03:30', 'Europe/Sofia', $this->now())->format('Y-m-d H:i:s'));
    }

    public function testAcceptsBackwardCompatibleZoneNames(): void
    {
        $this->assertSame('2026-10-22 05:00:00', Schedule::parse('2026-10-22T08:00', 'Europe/Kiev', $this->now())->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-22 02:30:00', Schedule::parse('2026-10-22T08:00', 'Asia/Calcutta', $this->now())->format('Y-m-d H:i:s'));
        $this->assertSame('Europe/Kyiv', Schedule::zone('Europe/Kiev')->getName());
        $this->assertSame('Europe/Sofia', Schedule::zone('Europe/Sofia')->getName());
    }

    /**
     * @dataProvider invalidZones
     */
    public function testRejectsNonLocationZones(string $zone): void
    {
        $this->expectException(InvalidInput::class);

        Schedule::zone($zone);
    }

    public function invalidZones(): array
    {
        return [['CEST'], ['+02:00'], ['europe/berlin'], ['Mars/Olympus'], ['']];
    }

    /**
     * @dataProvider invalidInputs
     */
    public function testRejectsInvalidInput(string $local, string $tz, string $label): void
    {
        $this->expectException(InvalidInput::class);
        $this->expectExceptionMessage($label);

        Schedule::parse($local, $tz, $this->now(), 60, 30 * 86400);
    }

    public function invalidInputs(): array
    {
        return [
            'unknown time zone'     => ['2026-10-22T08:00', 'Mars/Olympus', 'invalidtimezone'],
            'garbage'               => ['tomorrow', 'Europe/Berlin', 'invaliddate'],
            'impossible date'       => ['2026-02-30T08:00', 'Europe/Berlin', 'invaliddate'],
            'skipped by DST'        => ['2027-03-28T02:30', 'Europe/Berlin', 'invaliddate'],
            'past'                  => ['2026-10-21T13:00', 'Europe/Berlin', 'dateinpast'],
            'less than min lead'    => ['2026-10-21T14:00', 'Europe/Berlin', 'dateinpast'],
            'beyond the horizon'    => ['2026-12-01T08:00', 'Europe/Berlin', 'datetoofar'],
        ];
    }
}
