<?php

namespace ScheduledSend\Transport;

/**
 * The MTA did not accept the message. Temporary errors are retried later.
 */
class TransportException extends \RuntimeException
{
    /** @var bool */
    private $temporary;

    public function __construct(string $message, bool $temporary)
    {
        parent::__construct($message);

        $this->temporary = $temporary;
    }

    public function isTemporary(): bool
    {
        return $this->temporary;
    }
}
