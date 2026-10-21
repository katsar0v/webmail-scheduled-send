<?php

namespace ScheduledSend;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Turns the user's local date/time input into a validated UTC send time.
 */
class Schedule
{
    /**
     * @param string            $local      Local date/time as sent by <input type="datetime-local">, e.g. "2026-10-22T08:00"
     * @param string            $timezone   IANA time zone name, e.g. "Europe/Berlin"
     * @param DateTimeImmutable $now        Current time
     * @param int               $minLead    Minimum seconds between now and the send time
     * @param int               $maxHorizon Maximum seconds between now and the send time
     *
     * @return DateTimeImmutable Send time in UTC
     *
     * @throws InvalidInput With a localization label as message
     */
    public static function parse(string $local, string $timezone, DateTimeImmutable $now, int $minLead = 60, int $maxHorizon = 31536000): DateTimeImmutable
    {
        if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidInput('invalidtimezone');
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $local)) {
            throw new InvalidInput('invaliddate');
        }

        $tz   = new DateTimeZone($timezone);
        $time = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $local, $tz);

        // Reject impossible dates (e.g. Feb 30) and wall-clock times skipped by a DST change,
        // both of which PHP silently shifts to a different time.
        if ($time === false || $time->format('Y-m-d\TH:i') !== $local) {
            throw new InvalidInput('invaliddate');
        }

        $utc   = $time->setTimezone(new DateTimeZone('UTC'));
        $delta = $utc->getTimestamp() - $now->getTimestamp();

        if ($delta < $minLead) {
            throw new InvalidInput('dateinpast');
        }

        if ($delta > $maxHorizon) {
            throw new InvalidInput('datetoofar');
        }

        return $utc;
    }
}
