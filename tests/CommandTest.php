<?php

namespace ScheduledSend\Tests;

use PHPUnit\Framework\TestCase;
use ScheduledSend\Store\DoveadmStore;
use ScheduledSend\Store\StoreException;
use ScheduledSend\Transport\SendmailTransport;
use ScheduledSend\Transport\SmtpTransport;
use ScheduledSend\Transport\TransportException;

/**
 * Runs the sendmail/doveadm adapters against a stand-in script that records
 * its arguments and standard input, and the SMTP adapter against a fake server.
 */
class CommandTest extends TestCase
{
    /** @var string */
    private $dir;

    /** @var resource|null Fake SMTP server process */
    private $server;

    /** @var resource[] */
    private $serverPipes = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/scheduled_send_test_' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server, 9);
            array_map('fclose', $this->serverPipes);
            proc_close($this->server);
        }

        array_map('unlink', glob($this->dir . '/*'));
        rmdir($this->dir);
    }

    /**
     * @return string[] Command that records its arguments (one per line) and stdin, then exits with $code
     */
    private function recorder(int $code = 0): array
    {
        $script = $this->dir . '/record.sh';
        file_put_contents($script, sprintf("#!/bin/sh\nfor a in \"\$@\"; do printf '%%s\\n' \"\$a\"; done >> %s/args\ncat > %s/stdin\nexit %d\n",
            $this->dir, $this->dir, $code));

        return ['/bin/sh', $script];
    }

    /**
     * @return string[] Command that runs the given shell code instead of a mail program
     */
    private function standIn(string $body): array
    {
        $script = $this->dir . '/stand_in_' . bin2hex(random_bytes(3)) . '.sh';
        file_put_contents($script, "#!/bin/sh\n" . $body . "\n");

        return ['/bin/sh', $script];
    }

    private function args(): array
    {
        return file($this->dir . '/args', FILE_IGNORE_NEW_LINES);
    }

    public function testSendmailGetsEnvelopeAsSeparateArguments(): void
    {
        (new SendmailTransport($this->recorder()))->submit('alice@example.test', ['bob@example.test', 'carol@example.test'], "Subject: x\r\n\r\nBody");

        $this->assertSame(['-i', '-f', 'alice@example.test', '--', 'bob@example.test', 'carol@example.test'], $this->args());
        $this->assertSame("Subject: x\r\n\r\nBody", file_get_contents($this->dir . '/stdin'));
    }

    public function testSendmailTempfailIsTemporary(): void
    {
        try {
            (new SendmailTransport($this->recorder(75)))->submit('alice@example.test', ['bob@example.test'], 'x');
            $this->fail('No exception');
        }
        catch (TransportException $e) {
            $this->assertTrue($e->isTemporary());
        }
    }

    public function testSendmailOtherErrorsArePermanent(): void
    {
        try {
            (new SendmailTransport($this->recorder(67)))->submit('alice@example.test', ['bob@example.test'], 'x');
            $this->fail('No exception');
        }
        catch (TransportException $e) {
            $this->assertFalse($e->isTemporary());
        }
    }

    public function testSendmailTimeoutAfterInputIsUncertain(): void
    {
        // Takes the whole message, then hangs: it may have queued it
        $transport = new SendmailTransport($this->standIn("cat > /dev/null\nexec sleep 30"), 1);

        try {
            $transport->submit('alice@example.test', ['bob@example.test'], str_repeat('x', 300000));
            $this->fail('No exception');
        }
        catch (TransportException $e) {
            $this->assertTrue($e->isUncertain());
            $this->assertFalse($e->isTemporary());
        }
    }

    public function testSendmailThatNeverReadsInputIsTemporary(): void
    {
        $transport = new SendmailTransport($this->standIn('exec sleep 30'), 1);

        $started = microtime(true);

        try {
            $transport->submit('alice@example.test', ['bob@example.test'], str_repeat('x', 2000000));
            $this->fail('No exception');
        }
        catch (TransportException $e) {
            $this->assertTrue($e->isTemporary());
            $this->assertFalse($e->isUncertain());
        }

        $this->assertLessThan(8, microtime(true) - $started);
    }

    public function testSendmailExitingBeforeItReadAllInputIsTemporary(): void
    {
        // A permanent exit code, but the message was never handed over completely
        $transport = new SendmailTransport($this->standIn('exit 67'), 5);

        try {
            $transport->submit('alice@example.test', ['bob@example.test'], str_repeat('x', 2000000));
            $this->fail('No exception');
        }
        catch (TransportException $e) {
            $this->assertTrue($e->isTemporary());
            $this->assertFalse($e->isUncertain());
        }
    }

    public function testSendmailKilledAfterInputIsUncertain(): void
    {
        $transport = new SendmailTransport($this->standIn("cat > /dev/null\nkill -9 \$\$"), 5);

        try {
            $transport->submit('alice@example.test', ['bob@example.test'], 'x');
            $this->fail('No exception');
        }
        catch (TransportException $e) {
            $this->assertTrue($e->isUncertain());
        }
    }

    public function testSendmailNoisyStderrDoesNotBlock(): void
    {
        // Fills the stderr pipe before it reads anything
        $transport = new SendmailTransport($this->standIn("head -c 1500000 /dev/zero | tr '\\0' 'e' >&2\ncat > /dev/null"), 20);

        $transport->submit('alice@example.test', ['bob@example.test'], str_repeat('x', 1500000));

        $this->addToAssertionCount(1);
    }

    public function testSendmailCouldNotStartIsTemporary(): void
    {
        try {
            (new SendmailTransport([$this->dir . '/missing']))->submit('alice@example.test', ['bob@example.test'], 'x');
            $this->fail('No exception');
        }
        catch (TransportException $e) {
            $this->assertTrue($e->isTemporary());
        }
    }

    public function testTimeoutMustBePositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SendmailTransport(['/bin/true'], 0);
    }

    /**
     * @dataProvider invalidAddresses
     */
    public function testSendmailRejectsInvalidAddresses(string $from, array $to): void
    {
        $this->expectException(TransportException::class);

        (new SendmailTransport($this->recorder()))->submit($from, $to, 'x');
    }

    public function invalidAddresses(): array
    {
        return [
            'option injection' => ['-oQ/tmp@example.test', ['bob@example.test']],
            'display name'     => ['alice@example.test', ['Bob <bob@example.test>']],
            'newline'          => ["alice@example.test\n", ['bob@example.test']],
            'no recipients'    => ['alice@example.test', []],
        ];
    }

    public function testDoveadmSavesIntoUtf8FolderAndMarksSeen(): void
    {
        // "INBOX.Кацаров" in IMAP modified UTF-7
        $folder = mb_convert_encoding('INBOX.Кацаров', 'UTF7-IMAP', 'UTF-8');

        (new DoveadmStore($this->recorder()))->saveSent('alice@example.test', $folder, 'MIME', '<1@example.test>');

        $this->assertSame([
            'save', '-u', 'alice@example.test', '-m', 'INBOX.Кацаров',
            'flags', 'add', '-u', 'alice@example.test', '\\Seen', 'mailbox', 'INBOX.Кацаров', 'header', 'message-id', '<1@example.test>',
        ], $this->args());
    }

    public function testDoveadmForwardedFlag(): void
    {
        (new DoveadmStore($this->recorder()))->addFlag('alice@example.test', 'INBOX', '<0@example.test>', 'forwarded');

        $this->assertSame(['flags', 'add', '-u', 'alice@example.test', '$Forwarded', 'mailbox', 'INBOX', 'header', 'message-id', '<0@example.test>'], $this->args());
    }

    public function testDoveadmErrorIsReported(): void
    {
        $this->expectException(StoreException::class);

        (new DoveadmStore($this->recorder(68)))->addFlag('alice@example.test', 'INBOX', '<0@example.test>', 'answered');
    }

    public function testDoveadmSavedCopyIsNotRefusedWhenSeenFlagFails(): void
    {
        // "save" works, "flags" fails: a thrown error would make the worker file the copy again
        $command = $this->standIn("echo \"\$1\" >> {$this->dir}/calls\ncase \"\$1\" in save) cat > /dev/null;; flags) echo 'no such mailbox' >&2; exit 68;; esac");

        (new DoveadmStore($command))->saveSent('alice@example.test', 'Sent', 'MIME', '<1@example.test>');

        $this->assertSame(['save', 'flags'], file($this->dir . '/calls', FILE_IGNORE_NEW_LINES));
    }

    public function testDoveadmSaveFailureIsReported(): void
    {
        $this->expectException(StoreException::class);
        $this->expectExceptionMessage('exited with code 75: disk full');

        (new DoveadmStore($this->standIn("cat > /dev/null\necho 'disk full' >&2\nexit 75")))->saveSent('alice@example.test', 'Sent', 'MIME', '<1@example.test>');
    }

    public function testDoveadmTimeoutIsReported(): void
    {
        $this->expectException(StoreException::class);
        $this->expectExceptionMessage('did not finish within 1 seconds');

        (new DoveadmStore($this->standIn("cat > /dev/null\nexec sleep 30"), 1))->saveSent('alice@example.test', 'Sent', 'MIME', '<1@example.test>');
    }

    public function testDoveadmThatNeverReadsInputFails(): void
    {
        $this->expectException(StoreException::class);

        (new DoveadmStore($this->standIn('exec sleep 30'), 1))->saveSent('alice@example.test', 'Sent', str_repeat('x', 2000000), '<1@example.test>');
    }

    /**
     * Starts the fake SMTP server, which serves one connection and behaves according to $mode.
     *
     * @return int Port
     */
    private function smtpServer(string $mode): int
    {
        $script = $this->dir . '/smtp_server.php';
        file_put_contents($script, <<<'PHP'
<?php
$mode   = $argv[1];
$server = stream_socket_server('tcp://127.0.0.1:0');
$name   = stream_socket_get_name($server, false);
fwrite(STDOUT, substr($name, strrpos($name, ':') + 1) . "\n");

$conn  = stream_socket_accept($server, 20);
$reply = function (string $line) use ($conn) {
    fwrite($conn, $line . "\r\n");
};

$reply('220 fake ESMTP');

while (($line = fgets($conn)) !== false) {
    switch (strtoupper(substr($line, 0, 4))) {
        case 'EHLO':
        case 'HELO':
            $reply('250 fake');
            break;

        case 'MAIL':
            $reply('250 ok');
            break;

        case 'RCPT':
            $reply(['rcpt-4xx' => '451 try again later', 'rcpt-5xx' => '550 no such user'][$mode] ?? '250 ok');
            break;

        case 'DATA':
            if ($mode === 'data-refused') {
                $reply('554 no data please');
                break;
            }

            $reply('354 go ahead');

            if ($mode === 'abort-after-354') {
                exit;
            }

            $received = '';

            while (substr($received, -5) !== "\r\n.\r\n" && ($chunk = fread($conn, 8192)) !== false && $chunk !== '') {
                $received .= $chunk;
            }

            if ($mode === 'lose-final-reply') {
                // The message is in, the 250 never arrives
                exit;
            }

            $reply($mode === 'data-4xx' ? '451 queue is full' : ($mode === 'data-5xx' ? '554 rejected' : '250 queued'));
            break;

        case 'QUIT':
            $reply('221 bye');
            break 2;
    }
}
PHP
        );

        $this->server = proc_open([PHP_BINARY, $script, $mode], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $this->serverPipes);
        $this->assertIsResource($this->server);

        stream_set_timeout($this->serverPipes[1], 10);
        $port = (int) fgets($this->serverPipes[1]);
        $this->assertGreaterThan(0, $port, 'The fake SMTP server did not start');

        return $port;
    }

    private function smtpSubmit(string $mode): void
    {
        (new SmtpTransport('127.0.0.1', $this->smtpServer($mode)))
            ->submit('alice@example.test', ['bob@example.test'], "Subject: x\r\n\r\nBody\r\n");
    }

    public function testSmtpAcceptedMessage(): void
    {
        $this->smtpSubmit('accept');

        $this->addToAssertionCount(1);
    }

    /**
     * @dataProvider smtpFailures
     */
    public function testSmtpFailureClassification(string $mode, bool $temporary, bool $uncertain): void
    {
        try {
            $this->smtpSubmit($mode);
            $this->fail('No exception');
        }
        catch (TransportException $e) {
            $this->assertSame($temporary, $e->isTemporary(), $e->getMessage());
            $this->assertSame($uncertain, $e->isUncertain(), $e->getMessage());
        }
    }

    public function smtpFailures(): array
    {
        return [
            'recipient deferred'       => ['rcpt-4xx', true, false],
            'recipient refused'        => ['rcpt-5xx', false, false],
            'DATA deferred'            => ['data-4xx', true, false],
            'DATA refused'             => ['data-5xx', false, false],
            'DATA command refused'     => ['data-refused', false, false],
            'final reply lost'         => ['lose-final-reply', false, true],
            'connection lost in DATA'  => ['abort-after-354', false, true],
        ];
    }

    public function testSmtpConnectionRefusedIsTemporary(): void
    {
        // A port that was just free
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $name   = stream_socket_get_name($socket, false);
        fclose($socket);

        try {
            (new SmtpTransport('127.0.0.1', (int) substr($name, strrpos($name, ':') + 1)))->submit('alice@example.test', ['bob@example.test'], 'x');
            $this->fail('No exception');
        }
        catch (TransportException $e) {
            $this->assertTrue($e->isTemporary());
            $this->assertFalse($e->isUncertain());
        }
    }
}
