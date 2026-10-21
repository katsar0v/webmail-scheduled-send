<?php

namespace ScheduledSend\Tests;

use PHPUnit\Framework\TestCase;
use ScheduledSend\Store\DoveadmStore;
use ScheduledSend\Store\StoreException;
use ScheduledSend\Transport\SendmailTransport;
use ScheduledSend\Transport\TransportException;

/**
 * Runs the sendmail/doveadm adapters against a stand-in script that records
 * its arguments and standard input.
 */
class CommandTest extends TestCase
{
    /** @var string */
    private $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/scheduled_send_test_' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
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
}
