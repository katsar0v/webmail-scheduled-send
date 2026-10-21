<?php

namespace ScheduledSend\Tests;

use PHPUnit\Framework\TestCase;
use ScheduledSend\SenderPolicy;

class SenderPolicyTest extends TestCase
{
    public function testLoginPolicyAllowsOnlyTheLoginAddress(): void
    {
        $allowed = SenderPolicy::allowed(SenderPolicy::LOGIN, 'Alice@Example.test', ['alias@example.test']);

        $this->assertSame(['alice@example.test'], $allowed);
        $this->assertTrue(SenderPolicy::permits(['alice@example.test', 'ALICE@example.test'], $allowed));
        $this->assertFalse(SenderPolicy::permits(['alias@example.test'], $allowed));
    }

    public function testIdentitiesPolicyAddsIdentities(): void
    {
        $allowed = SenderPolicy::allowed(SenderPolicy::IDENTITIES, 'alice@example.test', ['Alias@example.test']);

        $this->assertTrue(SenderPolicy::permits(['alias@example.test'], $allowed));
        $this->assertFalse(SenderPolicy::permits(['mallory@example.test'], $allowed));
    }

    public function testEveryAddressMustBeAllowed(): void
    {
        $allowed = SenderPolicy::allowed(SenderPolicy::LOGIN, 'alice@example.test');

        $this->assertFalse(SenderPolicy::permits(['alice@example.test', 'bob@example.test'], $allowed));
        $this->assertFalse(SenderPolicy::permits([], $allowed));
    }

    public function testUnknownPolicyIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SenderPolicy::allowed('anyone', 'alice@example.test');
    }
}
