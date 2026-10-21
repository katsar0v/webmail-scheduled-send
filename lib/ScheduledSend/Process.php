<?php

namespace ScheduledSend;

/**
 * Runs a command with a deadline and returns what happened. Used for sendmail
 * and doveadm, where a stuck child must not block the worker (which holds the
 * global lock).
 *
 * The command is executed directly (no shell). Standard input, output and
 * error are multiplexed with non-blocking streams, so a child that fills its
 * output pipe before it reads its input cannot deadlock us.
 *
 * run() returns a finished Process; read the outcome with the getters.
 */
final class Process
{
    /** Bytes of combined output that are kept. The rest is read and dropped. */
    public const MAX_OUTPUT = 65536;

    /** Seconds a child gets to react to SIGTERM before it is killed */
    private const GRACE = 2;

    private const SIGTERM = 15;
    private const SIGKILL = 9;

    /** @var int|null */
    private $exitCode;

    /** @var string */
    private $output;

    /** @var bool */
    private $inputSent;

    /** @var bool */
    private $timedOut;

    private function __construct(?int $exitCode, string $output, bool $inputSent, bool $timedOut)
    {
        $this->exitCode  = $exitCode;
        $this->output    = $output;
        $this->inputSent = $inputSent;
        $this->timedOut  = $timedOut;
    }

    /**
     * @param string[] $argv    Program and arguments
     * @param string   $stdin   Written to the child's standard input, which is then closed
     * @param int      $timeout Seconds until the child is terminated
     *
     * @throws \InvalidArgumentException For an empty command or a timeout below 1 second
     * @throws \RuntimeException         If the command could not be started
     */
    public static function run(array $argv, string $stdin, int $timeout): self
    {
        if (empty($argv)) {
            throw new \InvalidArgumentException('Empty command');
        }

        if ($timeout < 1) {
            throw new \InvalidArgumentException('Timeout must be at least 1 second');
        }

        $deadline = self::now() + $timeout;
        $process  = @proc_open(array_values($argv), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if (!is_resource($process)) {
            throw new \RuntimeException('Could not start ' . $argv[0]);
        }

        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }

        $input     = $pipes[0];
        $offset    = 0;
        $length    = strlen($stdin);
        $inputSent = false;
        $writing   = true;
        $readers   = [1 => $pipes[1], 2 => $pipes[2]];
        $output    = '';
        $exitCode  = null;
        $timedOut  = false;

        if ($length === 0) {
            fclose($input);
            $writing   = false;
            $inputSent = true;
        }

        while (true) {
            $status = proc_get_status($process);

            if (!$status['running']) {
                // Only the first call after the exit has the real code
                $exitCode = $status['signaled'] ? null : (int) $status['exitcode'];

                // Take what is already in the pipes, but do not wait for EOF:
                // a grandchild may still hold them open
                foreach ($readers as $reader) {
                    self::drain($reader, $output, $deadline);
                }

                break;
            }

            $remaining = $deadline - self::now();

            if ($remaining <= 0) {
                $timedOut = true;

                // The child must be gone before it sees end-of-input on a partial message
                self::terminate($process);

                break;
            }

            $read  = $readers;
            $write = $writing ? [$input] : [];
            $other = null;

            if (!$read && !$write) {
                // Only the exit is left to wait for
                usleep(10000);
                continue;
            }

            // A short wait, so a child that exited but left a pipe open is noticed
            if (@stream_select($read, $write, $other, 0, (int) (min($remaining, 0.1) * 1e6)) === false) {
                continue;
            }

            foreach ($read as $reader) {
                $key = array_search($reader, $readers, true);

                if (self::readChunk($reader, $output) === null) {
                    fclose($reader);
                    unset($readers[$key]);
                }
            }

            if ($write) {
                $written = @fwrite($input, substr($stdin, $offset, 65536));

                if ($written === false) {
                    // The child closed its input or exited. Its exit code is still worth collecting.
                    $writing = false;
                }
                else {
                    $offset += $written;

                    if ($offset >= $length) {
                        fclose($input);
                        $writing   = false;
                        $inputSent = true;
                    }
                }
            }
        }

        // After a kill or timeout the descriptors are closed only now, with the child gone
        foreach ($readers as $reader) {
            fclose($reader);
        }

        if (is_resource($input)) {
            fclose($input);
        }

        // The return value is not usable: proc_get_status() has already reaped the child
        proc_close($process);

        return new self($exitCode, $output, $inputSent, $timedOut);
    }

    /**
     * @return int|null Exit code, or null if the child was killed (by us after the deadline, or by a signal)
     */
    public function exitCode(): ?int
    {
        return $this->exitCode;
    }

    /**
     * @return string Standard output and error in arrival order, at most MAX_OUTPUT bytes
     */
    public function output(): string
    {
        return $this->output;
    }

    /**
     * Whether every input byte was written and the child's input was closed (end-of-input sent).
     * False if the child exited or stopped reading first, or the deadline hit.
     */
    public function inputSent(): bool
    {
        return $this->inputSent;
    }

    public function timedOut(): bool
    {
        return $this->timedOut;
    }

    private static function now(): float
    {
        return hrtime(true) / 1e9;
    }

    /**
     * @param resource $stream
     *
     * @return int|null Bytes read (0 if nothing is available now), null at end-of-file
     */
    private static function readChunk($stream, string &$output): ?int
    {
        $chunk = fread($stream, 65536);

        if ($chunk === false || $chunk === '') {
            return feof($stream) ? null : 0;
        }

        $room = self::MAX_OUTPUT - strlen($output);

        if ($room > 0) {
            $output .= substr($chunk, 0, $room);
        }

        return strlen($chunk);
    }

    /**
     * @param resource $stream
     */
    private static function drain($stream, string &$output, float $deadline): void
    {
        while (self::now() < $deadline && self::readChunk($stream, $output)) {
        }
    }

    /**
     * SIGTERM first, so wrappers like sudo can pass it on to the real command; SIGKILL after the grace period.
     *
     * @param resource $process
     */
    private static function terminate($process): void
    {
        foreach ([[self::SIGTERM, self::GRACE], [self::SIGKILL, self::GRACE]] as [$signal, $wait]) {
            proc_terminate($process, $signal);

            $until = self::now() + $wait;

            do {
                if (!proc_get_status($process)['running']) {
                    return;
                }

                usleep(10000);
            } while (self::now() < $until);
        }
    }
}
