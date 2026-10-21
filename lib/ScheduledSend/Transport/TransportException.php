<?php

namespace ScheduledSend\Transport;

/**
 * The MTA did not accept the message. Temporary errors are retried later.
 *
 * Uncertain means the MTA may have accepted it anyway (for example the
 * connection or the process was lost after the whole message was sent). It
 * must not be sent again, so it is never temporary.
 */
class TransportException extends \RuntimeException
{
    /** @var bool */
    private $temporary;

    /** @var bool */
    private $uncertain;

    public function __construct(string $message, bool $temporary, bool $uncertain = false)
    {
        parent::__construct($message);

        $this->uncertain = $uncertain;
        $this->temporary = $temporary && !$uncertain;
    }

    public function isTemporary(): bool
    {
        return $this->temporary;
    }

    public function isUncertain(): bool
    {
        return $this->uncertain;
    }
}
