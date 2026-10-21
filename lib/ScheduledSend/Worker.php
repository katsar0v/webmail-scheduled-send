<?php

namespace ScheduledSend;

use DateTimeImmutable;
use ScheduledSend\Store\StoreInterface;
use ScheduledSend\Transport\TransportException;
use ScheduledSend\Transport\TransportInterface;

/**
 * Delivers due messages. Meant to run once per minute from cron or a systemd timer.
 *
 * A message is handed to the MTA at most once: if the worker dies while a
 * message is being submitted, the job becomes "uncertain" and is never retried
 * automatically.
 */
class Worker
{
    /** @var QueueInterface */
    private $queue;

    /** @var TransportInterface */
    private $transport;

    /** @var StoreInterface */
    private $store;

    /** @var callable(array $job): bool */
    private $senderAllowed;

    /** @var callable(string $message): void */
    private $log;

    /** @var array */
    private $options;

    /**
     * @param callable $senderAllowed Re-checks the job's sender right before delivery
     * @param callable $log           Receives one line per event
     * @param array    $options       batch, lease, max_attempts, retry_delay, retention_days, finish_timeout
     */
    public function __construct(QueueInterface $queue, TransportInterface $transport, StoreInterface $store,
        callable $senderAllowed, callable $log, array $options = [])
    {
        $this->queue         = $queue;
        $this->transport     = $transport;
        $this->store         = $store;
        $this->senderAllowed = $senderAllowed;
        $this->log           = $log;
        $this->options       = $options + [
            'batch'          => 50,
            'lease'          => 600,
            'max_attempts'   => 5,
            'retry_delay'    => 300,
            'retention_days' => 7,
            // How long to keep retrying the Sent copy and flags after delivery
            'finish_timeout' => 86400,
        ];
    }

    /**
     * @return array<string,int> Counters for logging
     */
    public function run(DateTimeImmutable $now): array
    {
        $stats = ['sent' => 0, 'retry' => 0, 'skipped' => 0, 'failed' => 0, 'uncertain' => 0, 'finished' => 0, 'purged' => 0];

        // Submission was interrupted: the MTA may or may not have the message.
        foreach ($this->queue->expiredLeaseIds(QueueInterface::SUBMITTING, $now) as $id) {
            if ($this->queue->transition($id, QueueInterface::SUBMITTING, QueueInterface::UNCERTAIN, [
                'last_error' => 'Worker was interrupted during submission; delivery state unknown',
            ])) {
                $this->log("job $id: uncertain");
                $stats['uncertain']++;
            }
        }

        // Accepted by the MTA, but the Sent copy or flags were not done yet.
        foreach ($this->queue->expiredLeaseIds(QueueInterface::ACCEPTED, $now) as $id) {
            $lease = Queue::datetime($now->modify('+' . $this->options['lease'] . ' seconds'));

            if ($this->queue->transition($id, QueueInterface::ACCEPTED, QueueInterface::ACCEPTED, ['lease_until' => $lease])
                && $this->finish($this->queue->find($id), $now)
            ) {
                $stats['finished']++;
            }
        }

        foreach ($this->queue->dueIds($now, $this->options['batch']) as $id) {
            $stats[$this->process($id, $now)]++;
        }

        $retention = $now->modify('-' . (int) $this->options['retention_days'] . ' days');
        $stats['purged'] = $this->queue->purge($retention);

        return $stats;
    }

    /**
     * @return string Stats key
     */
    private function process(int $id, DateTimeImmutable $now): string
    {
        $job = $this->queue->find($id);

        if (!$job) {
            return 'skipped';
        }

        $attempts = (int) $job['attempts'] + 1;
        $claimed  = $this->queue->transition($id, QueueInterface::PENDING, QueueInterface::SUBMITTING, [
            'attempts'    => $attempts,
            'lease_until' => Queue::datetime($now->modify('+' . $this->options['lease'] . ' seconds')),
        ]);

        if (!$claimed) {
            // Cancelled or taken by someone else in the meantime
            return 'skipped';
        }

        if (!($this->senderAllowed)($job)) {
            $this->fail($id, 'Sender is no longer allowed for this user');
            return 'failed';
        }

        $date       = MessageBuilder::date($now, $job['timezone']);
        $recipients = json_decode($job['recipients'], true) ?: [];
        $message    = MessageBuilder::forTransport($job['headers'], $job['body'], $date);

        try {
            $this->transport->submit($job['envelope_from'], $recipients, $message);
        }
        catch (TransportException $e) {
            if ($e->isUncertain()) {
                // The MTA may have the message: never send it again automatically
                $this->queue->transition($id, QueueInterface::SUBMITTING, QueueInterface::UNCERTAIN, [
                    'last_error'  => $e->getMessage(),
                    'lease_until' => null,
                ]);

                $this->log("job $id: uncertain: " . $e->getMessage());
                return 'uncertain';
            }

            if ($e->isTemporary() && $attempts < $this->options['max_attempts']) {
                $retryAt = $now->modify('+' . ($this->options['retry_delay'] * $attempts) . ' seconds');

                $this->queue->transition($id, QueueInterface::SUBMITTING, QueueInterface::PENDING, [
                    'send_at'     => Queue::datetime($retryAt),
                    'last_error'  => $e->getMessage(),
                    'lease_until' => null,
                ]);

                $this->log("job $id: temporary error, retry at " . $retryAt->format('c') . ': ' . $e->getMessage());
                return 'retry';
            }

            $this->fail($id, $e->getMessage());
            return 'failed';
        }

        $this->queue->transition($id, QueueInterface::SUBMITTING, QueueInterface::ACCEPTED, [
            'submitted_at' => Queue::datetime($now),
            'last_error'   => null,
        ]);

        $this->log("job $id: accepted by MTA, message-id {$job['message_id']}");

        $job['submitted_at'] = Queue::datetime($now);
        $this->finish($job, $now);

        return 'sent';
    }

    /**
     * Sent copy and Answered/Forwarded flag. Failures never cause a resend:
     * the job stays "accepted" with its content, and the missing steps are
     * retried on later runs. A finished step is recorded right away (by
     * clearing its column), so it does not run twice.
     *
     * @return bool True if the job is done
     */
    private function finish(array $job, DateTimeImmutable $now): bool
    {
        $id     = (int) $job['id'];
        $errors = [];
        $date   = MessageBuilder::date(new DateTimeImmutable($job['submitted_at'], new \DateTimeZone('UTC')), $job['timezone']);

        if (!empty($job['sent_mailbox'])) {
            try {
                $copy = MessageBuilder::forSentCopy($job['headers'], $job['body'], $date, $job['bcc']);
                $this->store->saveSent($job['username'], $job['sent_mailbox'], $copy, $job['message_id']);
                $this->queue->transition($id, QueueInterface::ACCEPTED, QueueInterface::ACCEPTED, ['sent_mailbox' => null]);
            }
            catch (\Exception $e) {
                $errors[] = 'Sent copy: ' . $e->getMessage();
            }
        }

        if (!empty($job['flag_name']) && !empty($job['flag_mailbox']) && !empty($job['flag_message_id'])) {
            try {
                $this->store->addFlag($job['username'], $job['flag_mailbox'], $job['flag_message_id'], $job['flag_name']);
                $this->queue->transition($id, QueueInterface::ACCEPTED, QueueInterface::ACCEPTED, ['flag_name' => null]);
            }
            catch (\Exception $e) {
                $errors[] = 'Flag: ' . $e->getMessage();
            }
        }

        foreach ($errors as $error) {
            $this->log("job $id: $error");
        }

        if (empty($errors)) {
            $this->queue->transition($id, QueueInterface::ACCEPTED, QueueInterface::DONE, [
                'last_error'  => null,
                'lease_until' => null,
                // The content is no longer needed once delivered and filed
                'headers'     => '',
                'body'        => '',
                'bcc'         => null,
            ]);

            return true;
        }

        $submitted = new DateTimeImmutable($job['submitted_at'], new \DateTimeZone('UTC'));

        if ($now->getTimestamp() - $submitted->getTimestamp() >= $this->options['finish_timeout']) {
            // Give up, but keep the content until the retention period ends, so it can be recovered by hand
            $this->queue->transition($id, QueueInterface::ACCEPTED, QueueInterface::DONE, [
                'last_error'  => 'Delivered, but gave up: ' . implode('; ', $errors),
                'lease_until' => null,
            ]);

            $this->log("job $id: gave up on Sent copy/flags");
            return true;
        }

        $this->queue->transition($id, QueueInterface::ACCEPTED, QueueInterface::ACCEPTED, [
            'last_error'  => 'Delivered; ' . implode('; ', $errors),
            'lease_until' => Queue::datetime($now->modify('+' . $this->options['retry_delay'] . ' seconds')),
        ]);

        return false;
    }

    private function fail(int $id, string $error): void
    {
        $this->queue->transition($id, QueueInterface::SUBMITTING, QueueInterface::FAILED, [
            'last_error'  => $error,
            'lease_until' => null,
        ]);

        $this->log("job $id: failed: $error");
    }

    private function log(string $message): void
    {
        ($this->log)($message);
    }
}
