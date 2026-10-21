<?php

namespace ScheduledSend\Transport;

use ScheduledSend\Process;

/**
 * Delivers through the local sendmail binary. On Plesk this is a wrapper that
 * runs the mail handlers and adds the DKIM signature.
 *
 * The command is executed directly (no shell), with the envelope passed as
 * separate arguments.
 */
class SendmailTransport implements TransportInterface
{
    /** sysexits.h EX_TEMPFAIL */
    private const EX_TEMPFAIL = 75;

    /** @var string[] */
    private $command;

    /** @var int */
    private $timeout;

    /**
     * @param string[] $command Program and fixed arguments, e.g. ['/usr/sbin/sendmail']
     *                          or ['/usr/bin/sudo', '-n', '-u', 'popuser', '/usr/sbin/sendmail']
     * @param int      $timeout Seconds sendmail gets to take over the message
     */
    public function __construct(array $command, int $timeout = 120)
    {
        if (empty($command)) {
            throw new \InvalidArgumentException('Empty sendmail command');
        }

        if ($timeout < 1) {
            throw new \InvalidArgumentException('Timeout must be at least 1 second');
        }

        $this->command = array_values($command);
        $this->timeout = $timeout;
    }

    public function submit(string $from, array $recipients, string $message): void
    {
        Addresses::assertValid($from, $recipients);

        // -i: a line with a single dot does not end the message
        $argv = array_merge($this->command, ['-i', '-f', $from, '--'], array_values($recipients));

        try {
            $result = Process::run($argv, $message, $this->timeout);
        }
        catch (\RuntimeException $e) {
            throw new TransportException('Could not start ' . $this->command[0], true);
        }

        $detail = trim(preg_replace('/\s+/', ' ', $result->output()));
        $detail = $detail !== '' ? ": $detail" : '';

        // Process kills the child before it sees end-of-input, so a truncated message is never queued
        if (!$result->inputSent()) {
            throw new TransportException(
                ($result->timedOut() ? 'Timed out writing the message to sendmail' : 'Could not write the message to sendmail') . $detail,
                true
            );
        }

        // sendmail has the whole message and may have queued it, so it must not be sent again
        if ($result->timedOut()) {
            throw new TransportException("sendmail did not finish within {$this->timeout} seconds$detail", false, true);
        }

        $code = $result->exitCode();

        if ($code === null) {
            throw new TransportException("sendmail was killed by a signal$detail", false, true);
        }

        if ($code !== 0) {
            throw new TransportException("sendmail exited with code $code$detail", $code === self::EX_TEMPFAIL);
        }
    }
}
