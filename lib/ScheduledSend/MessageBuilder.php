<?php

namespace ScheduledSend;

/**
 * Assembles the stored header block and body into a complete RFC 5322 message.
 *
 * The queue stores the headers without Date and Bcc: the Date is set when the
 * message actually goes out, and Bcc must only appear in the user's Sent copy.
 */
class MessageBuilder
{
    /**
     * Message as handed to the MTA (no Bcc header).
     */
    public static function forTransport(string $headers, string $body, string $date): string
    {
        return self::build($headers, $body, $date, null);
    }

    /**
     * Message as filed into the user's Sent folder (keeps Bcc, like Roundcube does).
     */
    public static function forSentCopy(string $headers, string $body, string $date, ?string $bcc): string
    {
        return self::build($headers, $body, $date, $bcc);
    }

    /**
     * RFC 2822 date in the user's time zone.
     */
    public static function date(\DateTimeImmutable $time, string $timezone): string
    {
        return $time->setTimezone(new \DateTimeZone($timezone))->format('D, d M Y H:i:s O');
    }

    private static function build(string $headers, string $body, string $date, ?string $bcc): string
    {
        $eol     = strpos($headers, "\r\n") !== false ? "\r\n" : "\n";
        $headers = rtrim($headers, "\r\n") . $eol;

        $message = 'Date: ' . $date . $eol . $headers;

        if ($bcc !== null && $bcc !== '') {
            $message .= 'Bcc: ' . $bcc . $eol;
        }

        return $message . $eol . $body;
    }
}
