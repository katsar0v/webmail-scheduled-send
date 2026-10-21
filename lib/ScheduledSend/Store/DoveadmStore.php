<?php

namespace ScheduledSend\Store;

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

    /**
     * @param string[] $command Program and fixed arguments
     */
    public function __construct(array $command)
    {
        if (empty($command)) {
            throw new \InvalidArgumentException('Empty doveadm command');
        }

        $this->command = array_values($command);
    }

    public function saveSent(string $user, string $mailbox, string $message, string $messageId): void
    {
        $this->run(['save', '-u', $user, '-m', self::utf8($mailbox)], $message);
        $this->addFlag($user, $mailbox, $messageId, self::SEEN);
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

        $argv    = array_merge($this->command, $args);
        $process = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if (!is_resource($process)) {
            throw new StoreException('Could not start ' . $this->command[0]);
        }

        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
        }

        fclose($pipes[0]);

        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        if (($code = proc_close($process)) !== 0) {
            $detail = trim(preg_replace('/\s+/', ' ', $output));
            throw new StoreException("doveadm {$args[0]} exited with code $code" . ($detail ? ": $detail" : ''));
        }
    }
}
