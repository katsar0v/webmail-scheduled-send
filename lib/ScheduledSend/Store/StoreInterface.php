<?php

namespace ScheduledSend\Store;

/**
 * Writes to the user's mailbox after delivery, without the user's password.
 */
interface StoreInterface
{
    public const SEEN      = 'seen';
    public const ANSWERED  = 'answered';
    public const FORWARDED = 'forwarded';

    /**
     * File a message into a folder and mark it as read.
     *
     * @throws StoreException
     */
    public function saveSent(string $user, string $mailbox, string $message, string $messageId): void;

    /**
     * Add a flag to the message(s) with the given Message-ID in a folder.
     *
     * @param string $flag One of the class constants
     *
     * @throws StoreException
     */
    public function addFlag(string $user, string $mailbox, string $messageId, string $flag): void;
}
