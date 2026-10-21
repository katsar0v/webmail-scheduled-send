<?php

namespace ScheduledSend\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ScheduledSend\QueueInterface;
use ScheduledSend\Store\StoreInterface;
use ScheduledSend\Tests\Fakes\FakeStore;
use ScheduledSend\Tests\Fakes\FakeTransport;
use ScheduledSend\Tests\Fakes\InMemoryQueue;
use ScheduledSend\Transport\TransportException;
use ScheduledSend\Worker;

class WorkerTest extends TestCase
{
    /** @var InMemoryQueue */
    private $queue;

    /** @var FakeTransport */
    private $transport;

    /** @var FakeStore */
    private $store;

    /** @var bool */
    private $senderAllowed = true;

    /** @var string[] */
    private $log = [];

    protected function setUp(): void
    {
        $this->queue     = new InMemoryQueue();
        $this->transport = new FakeTransport();
        $this->store     = new FakeStore();
    }

    private function worker(): Worker
    {
        return new Worker($this->queue, $this->transport, $this->store,
            function () { return $this->senderAllowed; },
            function ($line) { $this->log[] = $line; },
            ['max_attempts' => 2, 'retry_delay' => 300, 'lease' => 600]
        );
    }

    private function addJob(array $overrides = []): int
    {
        return $this->queue->add($overrides + [
            'user_id'       => 1,
            'username'      => 'alice@example.test',
            'send_at'       => '2026-10-22 06:00:00',
            'timezone'      => 'Europe/Berlin',
            'envelope_from' => 'alice@example.test',
            'recipients'    => json_encode(['bob@example.test', 'carol@example.test']),
            'message_id'    => '<1@example.test>',
            'headers'       => "From: alice@example.test\r\nTo: bob@example.test\r\nMessage-ID: <1@example.test>\r\n",
            'bcc'           => 'carol@example.test',
            'body'          => "Hello\r\n",
            'sent_mailbox'  => 'INBOX.Sent',
        ]);
    }

    private function time(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time . ' UTC');
    }

    public function testDoesNothingBeforeTheSendTime(): void
    {
        $id = $this->addJob();

        $this->worker()->run($this->time('2026-10-22 05:59:59'));

        $this->assertSame([], $this->transport->sent);
        $this->assertSame(QueueInterface::PENDING, $this->queue->jobs[$id]['status']);
    }

    public function testSendsDueMessageAndFilesSentCopy(): void
    {
        $id = $this->addJob(['flag_mailbox' => 'INBOX', 'flag_message_id' => '<0@example.test>', 'flag_name' => StoreInterface::ANSWERED]);

        $stats = $this->worker()->run($this->time('2026-10-22 06:00:30'));

        $this->assertSame(1, $stats['sent']);
        $this->assertCount(1, $this->transport->sent);

        $sent = $this->transport->sent[0];
        $this->assertSame('alice@example.test', $sent['from']);
        $this->assertSame(['bob@example.test', 'carol@example.test'], $sent['recipients']);
        $this->assertStringStartsWith("Date: Thu, 22 Oct 2026 08:00:30 +0200\r\n", $sent['message']);
        $this->assertStringNotContainsString('Bcc:', $sent['message']);

        $this->assertCount(1, $this->store->saved);
        $this->assertSame('INBOX.Sent', $this->store->saved[0]['mailbox']);
        $this->assertStringContainsString("Bcc: carol@example.test\r\n", $this->store->saved[0]['message']);
        // Sent copy carries the same Date as the delivered message
        $this->assertStringStartsWith("Date: Thu, 22 Oct 2026 08:00:30 +0200\r\n", $this->store->saved[0]['message']);

        $this->assertSame([['user' => 'alice@example.test', 'mailbox' => 'INBOX', 'messageId' => '<0@example.test>', 'flag' => 'answered']], $this->store->flags);

        $job = $this->queue->jobs[$id];
        $this->assertSame(QueueInterface::DONE, $job['status']);
        $this->assertSame('', $job['body'], 'content is dropped after delivery');
        $this->assertNull($job['last_error']);
    }

    public function testSendsEachMessageOnlyOnce(): void
    {
        $this->addJob();
        $worker = $this->worker();

        $worker->run($this->time('2026-10-22 06:01:00'));
        $worker->run($this->time('2026-10-22 06:02:00'));

        $this->assertCount(1, $this->transport->sent);
    }

    public function testRetriesTemporaryErrorsThenFails(): void
    {
        $id = $this->addJob();

        $this->transport->fail = new TransportException('451 try again', true);
        $this->worker()->run($this->time('2026-10-22 06:00:00'));

        $job = $this->queue->jobs[$id];
        $this->assertSame(QueueInterface::PENDING, $job['status']);
        $this->assertSame('2026-10-22 06:05:00', $job['send_at']);
        $this->assertSame('451 try again', $job['last_error']);

        // Second and last attempt fails as well
        $this->transport->fail = new TransportException('451 still busy', true);
        $this->worker()->run($this->time('2026-10-22 06:05:00'));

        $this->assertSame(QueueInterface::FAILED, $this->queue->jobs[$id]['status']);
        $this->assertSame([], $this->transport->sent);
    }

    public function testPermanentErrorFailsImmediately(): void
    {
        $id = $this->addJob();

        $this->transport->fail = new TransportException('550 no such user', false);
        $this->worker()->run($this->time('2026-10-22 06:00:00'));

        $this->assertSame(QueueInterface::FAILED, $this->queue->jobs[$id]['status']);
        $this->assertSame('Hello' . "\r\n", $this->queue->jobs[$id]['body'], 'failed jobs keep their content for "Edit"');
    }

    public function testRechecksTheSenderBeforeDelivery(): void
    {
        $id = $this->addJob();
        $this->senderAllowed = false;

        $this->worker()->run($this->time('2026-10-22 06:00:00'));

        $this->assertSame([], $this->transport->sent);
        $this->assertSame(QueueInterface::FAILED, $this->queue->jobs[$id]['status']);
    }

    public function testInterruptedSubmissionBecomesUncertainAndIsNotResent(): void
    {
        $id = $this->addJob();
        $this->queue->transition($id, QueueInterface::PENDING, QueueInterface::SUBMITTING, ['lease_until' => '2026-10-22 06:10:00']);

        $stats = $this->worker()->run($this->time('2026-10-22 06:11:00'));

        $this->assertSame(1, $stats['uncertain']);
        $this->assertSame(QueueInterface::UNCERTAIN, $this->queue->jobs[$id]['status']);
        $this->assertSame([], $this->transport->sent);
    }

    public function testSentCopyFailureKeepsContentAndRetriesWithoutResending(): void
    {
        $id = $this->addJob(['flag_mailbox' => 'INBOX', 'flag_message_id' => '<0@example.test>', 'flag_name' => StoreInterface::ANSWERED]);
        $this->store->failSave = true;

        $this->worker()->run($this->time('2026-10-22 06:00:00'));

        $job = $this->queue->jobs[$id];
        $this->assertSame(QueueInterface::ACCEPTED, $job['status']);
        $this->assertSame("Hello\r\n", $job['body'], 'content is kept until the Sent copy exists');
        $this->assertStringContainsString('mailbox full', $job['last_error']);
        $this->assertNull($job['flag_name'], 'the flag step succeeded and is not repeated');
        $this->assertCount(1, $this->store->flags);

        // Mailbox has room again; the next run after the retry delay files the copy
        $this->store->failSave = false;
        $this->worker()->run($this->time('2026-10-22 06:06:00'));

        $job = $this->queue->jobs[$id];
        $this->assertSame(QueueInterface::DONE, $job['status']);
        $this->assertSame('', $job['body']);
        $this->assertCount(1, $this->store->saved);
        $this->assertCount(1, $this->store->flags);
        $this->assertCount(1, $this->transport->sent, 'never resent');
        // The copy has the original send time, not the time of the retry
        $this->assertStringStartsWith("Date: Thu, 22 Oct 2026 08:00:00 +0200\r\n", $this->store->saved[0]['message']);
    }

    public function testGivesUpOnSentCopyAfterTimeoutButKeepsContent(): void
    {
        $id = $this->addJob();
        $this->store->failSave = true;

        $this->worker()->run($this->time('2026-10-22 06:00:00'));
        $this->worker()->run($this->time('2026-10-23 06:00:01'));

        $job = $this->queue->jobs[$id];
        $this->assertSame(QueueInterface::DONE, $job['status']);
        $this->assertStringContainsString('gave up', $job['last_error']);
        $this->assertSame("Hello\r\n", $job['body'], 'kept for manual recovery until purged');
        $this->assertCount(1, $this->transport->sent);
    }

    public function testUncertainSubmissionIsNeverRetried(): void
    {
        $id = $this->addJob();

        $this->transport->fail = new TransportException('sendmail timed out after reading the message', false, true);
        $stats = $this->worker()->run($this->time('2026-10-22 06:00:00'));
        $this->worker()->run($this->time('2026-10-22 07:00:00'));

        $this->assertSame(1, $stats['uncertain']);
        $this->assertSame(QueueInterface::UNCERTAIN, $this->queue->jobs[$id]['status']);
        $this->assertSame([], $this->transport->sent);
    }

    public function testFinishesAcceptedJobsLeftBehind(): void
    {
        $id = $this->addJob();
        $this->queue->transition($id, QueueInterface::PENDING, QueueInterface::ACCEPTED, [
            'submitted_at' => '2026-10-22 06:00:00',
            'lease_until'  => '2026-10-22 06:10:00',
        ]);

        $this->worker()->run($this->time('2026-10-22 06:11:00'));

        $this->assertSame([], $this->transport->sent, 'already accepted, never resent');
        $this->assertCount(1, $this->store->saved);
        $this->assertStringStartsWith("Date: Thu, 22 Oct 2026 08:00:00 +0200\r\n", $this->store->saved[0]['message']);
        $this->assertSame(QueueInterface::DONE, $this->queue->jobs[$id]['status']);
    }

    public function testSkipsJobsCancelledInTheMeantime(): void
    {
        $id = $this->addJob();
        $this->queue->transition($id, QueueInterface::PENDING, QueueInterface::CANCELLED);

        $this->worker()->run($this->time('2026-10-22 06:00:00'));

        $this->assertSame([], $this->transport->sent);
    }
}
