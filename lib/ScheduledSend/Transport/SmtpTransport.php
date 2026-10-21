<?php

namespace ScheduledSend\Transport;

/**
 * Delivers over unauthenticated SMTP. Meant for development and test setups;
 * on Plesk use SendmailTransport, because relaying from localhost is refused
 * and would not be DKIM-signed.
 */
class SmtpTransport implements TransportInterface
{
    /** Seconds without a reply from the server before giving up */
    private const IO_TIMEOUT = 60;

    /** @var string */
    private $host;

    /** @var int */
    private $port;

    public function __construct(string $host, int $port = 25)
    {
        $this->host = $host;
        $this->port = $port;
    }

    public function submit(string $from, array $recipients, string $message): void
    {
        Addresses::assertValid($from, $recipients);

        $smtp = new \Net_SMTP($this->host, $this->port, gethostname() ?: 'localhost', false, self::IO_TIMEOUT);

        try {
            $this->check($smtp, $smtp->connect(30), "Connect to {$this->host}:{$this->port}", false);
            $this->check($smtp, $smtp->mailFrom($from), 'MAIL FROM', false);

            foreach ($recipients as $recipient) {
                $this->check($smtp, $smtp->rcptTo($recipient), 'RCPT TO', false);
            }

            $this->check($smtp, $smtp->data($message), 'DATA', true);
        }
        finally {
            $smtp->disconnect();
        }
    }

    /**
     * @param bool $data Whether this is the DATA step, where the server may accept the message
     *                   without us learning it
     */
    private function check(\Net_SMTP $smtp, $result, string $step, bool $data): void
    {
        if (!self::isError($result)) {
            return;
        }

        [$code, $text] = $smtp->getResponse() + [0, ''];
        $code = (int) $code;

        $message = "$step failed: " . ($code > 0 ? "$code $text" : $result->getMessage());

        // An explicit refusal: 4xx is worth retrying, 5xx is final
        if ($code >= 400 && $code < 600) {
            throw new TransportException($message, $code < 500);
        }

        // No usable reply (Net_SMTP leaves 0 or -1) or a lost connection. Before DATA nothing was
        // accepted. In DATA the server may have queued the message and the final 250 got lost
        // (or the connection broke after 354), so sending it again could deliver it twice.
        throw new TransportException($message, !$data, $data);
    }

    private static function isError($result): bool
    {
        return $result instanceof \PEAR_Error;
    }
}
