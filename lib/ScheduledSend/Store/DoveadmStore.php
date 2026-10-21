<?php

namespace ScheduledSend\Store;

use ScheduledSend\Process;

/**
 * Uses Dovecot's doveadm, which needs no user password. doveadm must run as a
 * user that can open Dovecot's auth-userdb socket (on Plesk: popuser), e.g.
 * ['/usr/bin/sudo', '-n', '-u', 'popuser', '/usr/bin/doveadm'].
 */
class DoveadmStore implements StoreInterface
{
    private const FLAGS = [
        self::SEEN      => '\\Seen',
        self::ANSWERED  => '\\Answered',
        self::FORWARDED => '$Forwarded',
    ];

    /** @var string[] */
    private $command;

    /** @var int */
    private $timeout;

    /**
     * @param string[] $command Program and fixed arguments
     * @param int      $timeout Seconds a single doveadm call may take
     */
    public function __construct(array $command, int $timeout = 60)
    {
        if (empty($command)) {
            throw new \InvalidArgumentException('Empty doveadm command');
        }

        if ($timeout < 1) {
            throw new \InvalidArgumentException('Timeout must be at least 1 second');
        }

        $this->command = array_values($command);
        $this->timeout = $timeout;
    }

    public function saveSent(string $user, string $mailbox, string $message, string $messageId): void
    {
        $this->run(['save', '-u', $user, '-m', self::utf8($mailbox)], $message);

        try {
            $this->addFlag($user, $mailbox, $messageId, self::SEEN);
        }
        catch (StoreException $e) {
            // The copy is saved and a failed Sent copy is retried, so throwing here would
            // file it twice. It just stays unread.
        }
    }

    public function addFlag(string $user, string $mailbox, string $messageId, string $flag): void
    {
        if (!isset(self::FLAGS[$flag])) {
            throw new \InvalidArgumentException("Unknown flag: $flag");
        }

        $this->run(['flags', 'add', '-u', $user, self::FLAGS[$flag], 'mailbox', self::utf8($mailbox), 'header', 'message-id', $messageId]);
    }

    /**
     * Roundcube uses IMAP's modified UTF-7 folder names, doveadm expects UTF-8.
     */
    private static function utf8(string $mailbox): string
    {
        return mb_convert_encoding($mailbox, 'UTF-8', 'UTF7-IMAP');
    }

    private function run(array $args, ?string $stdin = null): void
    {
        foreach ($args as $arg) {
            if (preg_match('/[\x00-\x1f\x7f]/', $arg)) {
                throw new StoreException('Invalid doveadm argument: ' . json_encode($arg));
            }
        }

        try {
            $result = Process::run(array_merge($this->command, $args), $stdin ?? '', $this->timeout);
        }
        catch (\RuntimeException $e) {
            throw new StoreException('Could not start ' . $this->command[0]);
        }

        $detail = trim(preg_replace('/\s+/', ' ', $result->output()));
        $detail = $detail !== '' ? ": $detail" : '';

        if ($result->timedOut()) {
            throw new StoreException("doveadm {$args[0]} did not finish within {$this->timeout} seconds$detail");
        }

        $code = $result->exitCode();

        if ($code !== 0) {
            throw new StoreException("doveadm {$args[0]} " . ($code === null ? 'was killed by a signal' : "exited with code $code") . $detail);
        }

        // Do not accept a Sent copy that doveadm closed its input on too early
        if (!$result->inputSent()) {
            throw new StoreException("doveadm {$args[0]} stopped reading its input$detail");
        }
    }
}
