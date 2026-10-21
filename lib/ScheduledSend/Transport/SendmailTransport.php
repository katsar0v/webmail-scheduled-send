<?php

namespace ScheduledSend\Transport;

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

    /**
     * @param string[] $command Program and fixed arguments, e.g. ['/usr/sbin/sendmail']
     *                          or ['/usr/bin/sudo', '-n', '-u', 'popuser', '/usr/sbin/sendmail']
     */
    public function __construct(array $command)
    {
        if (empty($command)) {
            throw new \InvalidArgumentException('Empty sendmail command');
        }

        $this->command = array_values($command);
    }

    public function submit(string $from, array $recipients, string $message): void
    {
        Addresses::assertValid($from, $recipients);

        // -i: a line with a single dot does not end the message
        $argv = array_merge($this->command, ['-i', '-f', $from, '--'], array_values($recipients));

        $process = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if (!is_resource($process)) {
            throw new TransportException('Could not start ' . $this->command[0], true);
        }

        if (!self::writeAll($pipes[0], $message)) {
            // Kill sendmail before it sees end-of-input, so it cannot queue a truncated message
            proc_terminate($process, 9);
            fclose($pipes[0]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);

            throw new TransportException('Could not write the message to sendmail', true);
        }

        fclose($pipes[0]);

        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $code = proc_close($process);

        if ($code !== 0) {
            $detail = trim(preg_replace('/\s+/', ' ', $output));
            throw new TransportException("sendmail exited with code $code" . ($detail ? ": $detail" : ''), $code === self::EX_TEMPFAIL);
        }
    }

    /**
     * @param resource $pipe
     */
    private static function writeAll($pipe, string $data): bool
    {
        for ($offset = 0, $length = strlen($data); $offset < $length; $offset += $written) {
            $written = @fwrite($pipe, substr($data, $offset, 65536));

            if ($written === false || $written === 0) {
                return false;
            }
        }

        return true;
    }
}
