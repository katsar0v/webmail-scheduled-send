<?php

namespace ScheduledSend\Tests\Fakes;

use ScheduledSend\Transport\TransportException;
use ScheduledSend\Transport\TransportInterface;

class FakeTransport implements TransportInterface
{
    /** @var array[] */
    public $sent = [];

    /** @var TransportException|null Thrown by the next submit() */
    public $fail;

    public function submit(string $from, array $recipients, string $message): void
    {
        if ($this->fail) {
            $error      = $this->fail;
            $this->fail = null;
            throw $error;
        }

        $this->sent[] = compact('from', 'recipients', 'message');
    }
}
