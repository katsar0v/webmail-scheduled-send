<?php

namespace ScheduledSend\Transport;

/**
 * Delivers over unauthenticated SMTP. Meant for development and test setups;
 * on Plesk use SendmailTransport, because relaying from localhost is refused
 * and would not be DKIM-signed.
 */
class SmtpTransport implements TransportInterface
{
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

        $smtp = new \Net_SMTP($this->host, $this->port, gethostname() ?: 'localhost');

        if (self::isError($smtp->connect(30))) {
            throw new TransportException("Could not connect to {$this->host}:{$this->port}", true);
        }

        try {
            $this->check($smtp, $smtp->mailFrom($from), 'MAIL FROM');

            foreach ($recipients as $recipient) {
                $this->check($smtp, $smtp->rcptTo($recipient), 'RCPT TO');
            }

            $this->check($smtp, $smtp->data($message), 'DATA');
        }
        finally {
            $smtp->disconnect();
        }
    }

    private function check(\Net_SMTP $smtp, $result, string $step): void
    {
        if (!self::isError($result)) {
            return;
        }

        [$code, $text] = $smtp->getResponse() + [0, ''];
        $code = (int) $code;

        // 4xx and lost connections are worth retrying, 5xx are final
        throw new TransportException("$step failed: $code $text", $code < 500);
    }

    private static function isError($result): bool
    {
        return $result instanceof \PEAR_Error;
    }
}
