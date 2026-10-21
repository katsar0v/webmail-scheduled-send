<?php

namespace ScheduledSend\Transport;

/**
 * Hands a finished message to the MTA.
 */
interface TransportInterface
{
    /**
     * @param string   $from       Envelope sender
     * @param string[] $recipients Envelope recipients (To, Cc and Bcc)
     * @param string   $message    Complete message without Bcc header
     *
     * @throws TransportException If the MTA did not accept the message
     */
    public function submit(string $from, array $recipients, string $message): void;
}
