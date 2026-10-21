<?php

namespace ScheduledSend\Store;

/**
 * Keeps no Sent copy and sets no flags.
 */
class NullStore implements StoreInterface
{
    public function saveSent(string $user, string $mailbox, string $message, string $messageId): void
    {
    }

    public function addFlag(string $user, string $mailbox, string $messageId, string $flag): void
    {
    }
}
