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
     * Old names that browsers (via ICU) still report, mapped to current IANA names.
     * Not every PHP build ships the backward-compatible aliases.
     */
    private const ALIASES = [
        'Africa/Asmera'          => 'Africa/Asmara',
        'America/Buenos_Aires'   => 'America/Argentina/Buenos_Aires',
        'America/Catamarca'      => 'America/Argentina/Catamarca',
        'America/Cordoba'        => 'America/Argentina/Cordoba',
        'America/Godthab'        => 'America/Nuuk',
        'America/Indianapolis'   => 'America/Indiana/Indianapolis',
        'America/Jujuy'          => 'America/Argentina/Jujuy',
        'America/Louisville'     => 'America/Kentucky/Louisville',
        'America/Mendoza'        => 'America/Argentina/Mendoza',
        'Asia/Calcutta'          => 'Asia/Kolkata',
        'Asia/Chongqing'         => 'Asia/Shanghai',
        'Asia/Chungking'         => 'Asia/Shanghai',
        'Asia/Dacca'             => 'Asia/Dhaka',
        'Asia/Katmandu'          => 'Asia/Kathmandu',
        'Asia/Macao'             => 'Asia/Macau',
        'Asia/Rangoon'           => 'Asia/Yangon',
        'Asia/Saigon'            => 'Asia/Ho_Chi_Minh',
        'Asia/Thimbu'            => 'Asia/Thimphu',
        'Asia/Ujung_Pandang'     => 'Asia/Makassar',
        'Asia/Ulan_Bator'        => 'Asia/Ulaanbaatar',
        'Atlantic/Faeroe'        => 'Atlantic/Faroe',
        'Australia/ACT'          => 'Australia/Sydney',
        'Australia/Canberra'     => 'Australia/Sydney',
        'Europe/Kiev'            => 'Europe/Kyiv',
        'Europe/Uzhgorod'        => 'Europe/Kyiv',
        'Europe/Zaporozhye'      => 'Europe/Kyiv',
        'Pacific/Enderbury'      => 'Pacific/Kanton',
        'Pacific/Ponape'         => 'Pacific/Pohnpei',
        'Pacific/Truk'           => 'Pacific/Chuuk',
    ];

    /**
     * Resolves a browser time zone name to a canonical, location-based zone.
     *
     * @throws InvalidInput For abbreviations, offsets and unknown names
     */
    public static function zone(string $name): DateTimeZone
    {
        // The identifier list holds exact, location-based names only: no "CEST", "+02:00" or wrong case
        $known = DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC);

        foreach ([$name, self::ALIASES[$name] ?? null] as $candidate) {
            if ($candidate !== null && in_array($candidate, $known, true)) {
                return new DateTimeZone($candidate);
            }
        }

        throw new InvalidInput('invalidtimezone');
    }

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
        $tz = self::zone($timezone);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $local)) {
            throw new InvalidInput('invaliddate');
        }

        $time = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $local, $tz);

        // Reject impossible dates (e.g. Feb 30) and wall-clock times skipped by a DST change,
        // both of which PHP silently shifts to a different time.
        if ($time === false || $time->format('Y-m-d\TH:i') !== $local) {
            throw new InvalidInput('invaliddate');
        }

        $utc = self::firstOccurrence($time->setTimezone(new DateTimeZone('UTC')), $tz, $local);
        $delta = $utc->getTimestamp() - $now->getTimestamp();

        if ($delta < $minLead) {
            throw new InvalidInput('dateinpast');
        }

        if ($delta > $maxHorizon) {
            throw new InvalidInput('datetoofar');
        }

        return $utc;
    }

    /**
     * When the clocks go back, a wall-clock time exists twice. PHP does not
     * reliably pick the earlier one, so check whether an earlier instant shows
     * the same local time and use that.
     */
    private static function firstOccurrence(DateTimeImmutable $utc, DateTimeZone $tz, string $local): DateTimeImmutable
    {
        // DST shifts are one hour almost everywhere, 30 minutes on Lord Howe Island
        foreach ([3600, 1800] as $shift) {
            $earlier = $utc->modify("-$shift seconds");

            if ($earlier->setTimezone($tz)->format('Y-m-d\TH:i') === $local) {
                return $earlier;
            }
        }

        return $utc;
    }
}
