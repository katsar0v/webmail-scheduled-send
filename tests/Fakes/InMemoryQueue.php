<?php

namespace ScheduledSend\Tests\Fakes;

use ScheduledSend\Queue;
use ScheduledSend\QueueInterface;

class InMemoryQueue implements QueueInterface
{
    /** @var array<int,array> */
    public $jobs = [];

    public function add(array $job): int
    {
        $id = count($this->jobs) + 1;

        $this->jobs[$id] = $job + [
            'id'           => $id,
            'status'       => self::PENDING,
            'attempts'     => 0,
            'last_error'   => null,
            'lease_until'  => null,
            'submitted_at' => null,
            'updated_at'   => Queue::datetime(new \DateTimeImmutable()),
        ];

        return $id;
    }

    public function find(int $id, ?int $userId = null): ?array
    {
        $job = $this->jobs[$id] ?? null;

        return $job && ($userId === null || $job['user_id'] == $userId) ? $job : null;
    }

    public function listForUser(int $userId): array
    {
        return array_values(array_filter($this->jobs, function ($job) use ($userId) {
            return $job['user_id'] == $userId;
        }));
    }

    public function usage(int $userId): array
    {
        $jobs = array_filter($this->jobs, function ($job) use ($userId) {
            return $job['user_id'] == $userId && in_array($job['status'], self::HOLDING_CONTENT, true);
        });

        return ['count' => count($jobs), 'bytes' => array_sum(array_column($jobs, 'size'))];
    }

    public function findByMessageId(int $userId, string $messageId, array $statuses): ?array
    {
        foreach (array_reverse($this->jobs) as $job) {
            if ($job['user_id'] == $userId && $job['message_id'] === $messageId && in_array($job['status'], $statuses, true)) {
                return $job;
            }
        }

        return null;
    }

    public function transition(int $id, string $from, string $to, array $set = [], ?int $userId = null): bool
    {
        $job = $this->find($id, $userId);

        if (!$job || $job['status'] !== $from) {
            return false;
        }

        $this->jobs[$id] = ['status' => $to, 'updated_at' => Queue::datetime(new \DateTimeImmutable())] + $set + $job;

        return true;
    }

    public function dueIds(\DateTimeImmutable $now, int $limit): array
    {
        $due = array_filter($this->jobs, function ($job) use ($now) {
            return $job['status'] === self::PENDING && $job['send_at'] <= Queue::datetime($now);
        });

        return array_slice(array_keys($due), 0, $limit);
    }

    public function expiredLeaseIds(string $status, \DateTimeImmutable $now): array
    {
        return array_keys(array_filter($this->jobs, function ($job) use ($status, $now) {
            return $job['status'] === $status && $job['lease_until'] !== null && $job['lease_until'] < Queue::datetime($now);
        }));
    }

    public function purge(\DateTimeImmutable $before): int
    {
        $count = 0;

        foreach ($this->jobs as $id => $job) {
            if (in_array($job['status'], [self::DONE, self::CANCELLED, self::FAILED], true) && $job['updated_at'] < Queue::datetime($before)) {
                unset($this->jobs[$id]);
                $count++;
            }
        }

        return $count;
    }
}
