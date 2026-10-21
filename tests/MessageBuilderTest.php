<?php

namespace ScheduledSend\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ScheduledSend\MessageBuilder;

class MessageBuilderTest extends TestCase
{
    private const HEADERS = "From: alice@example.test\r\nTo: bob@example.test\r\nSubject: Hi\r\nMessage-ID: <1@example.test>\r\n";

    public function testTransportCopyHasDateAndNoBcc(): void
    {
        $message = MessageBuilder::forTransport(self::HEADERS, "Body\r\n", 'Thu, 22 Oct 2026 08:00:00 +0200');

        $this->assertStringStartsWith("Date: Thu, 22 Oct 2026 08:00:00 +0200\r\nFrom: alice@example.test\r\n", $message);
        $this->assertStringContainsString("Message-ID: <1@example.test>\r\n\r\nBody\r\n", $message);
        $this->assertStringNotContainsString('Bcc:', $message);
    }

    public function testSentCopyKeepsBcc(): void
    {
        $message = MessageBuilder::forSentCopy(self::HEADERS, "Body\r\n", 'Thu, 22 Oct 2026 08:00:00 +0200', 'carol@example.test');

        $this->assertStringContainsString("Message-ID: <1@example.test>\r\nBcc: carol@example.test\r\n\r\nBody\r\n", $message);
    }

    public function testDraftCarriesDraftInfo(): void
    {
        $message = MessageBuilder::forDraft(self::HEADERS, "Body\r\n", 'D', 'carol@example.test', 'type=reply; uid=7; folder=B::SU5CT1g=');

        $this->assertStringStartsWith("X-Draft-Info: type=reply; uid=7; folder=B::SU5CT1g=\r\nDate: D\r\n", $message);
        $this->assertStringContainsString("Bcc: carol@example.test\r\n\r\nBody\r\n", $message);
        $this->assertStringStartsWith('Date: D', MessageBuilder::forDraft(self::HEADERS, '', 'D', null, null));
    }

    public function testKeepsUnixLineEndings(): void
    {
        $message = MessageBuilder::forSentCopy("From: a@example.test\nSubject: x\n", "Body\n", 'D', 'b@example.test');

        $this->assertSame("Date: D\nFrom: a@example.test\nSubject: x\nBcc: b@example.test\n\nBody\n", $message);
    }

    public function testDateUsesTheUsersTimeZone(): void
    {
        $time = new DateTimeImmutable('2026-10-22 06:00:00 UTC');

        $this->assertSame('Thu, 22 Oct 2026 08:00:00 +0200', MessageBuilder::date($time, 'Europe/Berlin'));
    }
}
