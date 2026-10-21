<?php

namespace ScheduledSend\Transport;

/**
 * Guards envelope addresses before they reach a command line or an SMTP dialog.
 */
class Addresses
{
    /**
     * @throws TransportException
     */
    public static function assertValid(string $from, array $recipients): void
    {
        if (empty($recipients)) {
            throw new TransportException('No recipients', false);
        }

        foreach (array_merge([$from], $recipients) as $address) {
            // A plain addr-spec: no whitespace, control characters or angle brackets,
            // must not look like a command line option.
            if (!is_string($address)
                || !preg_match('/^[^\s<>@]+@[^\s<>@]+$/', $address)
                || preg_match('/[\x00-\x1f\x7f]/', $address)
                || $address[0] === '-'
            ) {
                throw new TransportException('Invalid envelope address: ' . json_encode($address), false);
            }
        }
    }
}
