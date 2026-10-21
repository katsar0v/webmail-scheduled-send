<?php

namespace ScheduledSend\Tests\Fakes;

use ScheduledSend\Store\StoreException;
use ScheduledSend\Store\StoreInterface;

class FakeStore implements StoreInterface
{
    /** @var array[] */
    public $saved = [];

    /** @var array[] */
    public $flags = [];

    /** @var bool */
    public $failSave = false;

    public function saveSent(string $user, string $mailbox, string $message, string $messageId): void
    {
        if ($this->failSave) {
            throw new StoreException('mailbox full');
        }

        $this->saved[] = compact('user', 'mailbox', 'message', 'messageId');
    }

    public function addFlag(string $user, string $mailbox, string $messageId, string $flag): void
    {
        $this->flags[] = compact('user', 'mailbox', 'messageId', 'flag');
    }
}
