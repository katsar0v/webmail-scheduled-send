<?php

namespace ScheduledSend\Store;

/**
 * Writes over IMAP with a fixed password. Only for development and test
 * servers that accept any password (e.g. GreenMail with auth disabled);
 * a real server would need the user's password, which the plugin never stores.
 */
class ImapStore implements StoreInterface
{
    private const FLAGS = [
        self::SEEN      => 'SEEN',
        self::ANSWERED  => 'ANSWERED',
        self::FORWARDED => 'FORWARDED',
    ];

    /** @var string */
    private $host;

    /** @var int */
    private $port;

    /** @var string */
    private $password;

    public function __construct(string $host, int $port, string $password)
    {
        $this->host     = $host;
        $this->port     = $port;
        $this->password = $password;
    }

    public function saveSent(string $user, string $mailbox, string $message, string $messageId): void
    {
        $imap = $this->connect($user);

        try {
            if (!$imap->append($mailbox, $message, ['SEEN'])) {
                throw new StoreException("IMAP APPEND to $mailbox failed: " . $imap->error);
            }
        }
        finally {
            $imap->closeConnection();
        }
    }

    public function addFlag(string $user, string $mailbox, string $messageId, string $flag): void
    {
        if (!isset(self::FLAGS[$flag])) {
            throw new \InvalidArgumentException("Unknown flag: $flag");
        }

        $imap = $this->connect($user);

        try {
            $uids = $imap->search($mailbox, 'HEADER MESSAGE-ID ' . \rcube_imap_generic::escape($messageId), true);

            if ($uids->is_error()) {
                throw new StoreException("IMAP SEARCH in $mailbox failed: " . $imap->error);
            }

            if (!$uids->is_empty() && !$imap->flag($mailbox, $uids->get(), self::FLAGS[$flag])) {
                throw new StoreException("IMAP STORE in $mailbox failed: " . $imap->error);
            }
        }
        finally {
            $imap->closeConnection();
        }
    }

    private function connect(string $user): \rcube_imap_generic
    {
        $imap = new \rcube_imap_generic();

        if (!$imap->connect($this->host, $user, $this->password, ['port' => $this->port, 'ssl_mode' => false, 'timeout' => 30])) {
            throw new StoreException("IMAP login as $user failed: " . $imap->error);
        }

        return $imap;
    }
}
