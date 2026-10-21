<?php

namespace ScheduledSend;

/**
 * Persistent store of scheduled messages.
 *
 * Status flow:
 *
 *   pending → submitting → accepted → done
 *      │           │
 *      │           ├→ pending (temporary error, retried later)
 *      │           ├→ failed  (permanent error or too many attempts)
 *      │           └→ uncertain (worker died mid-submission; never resent automatically)
 *      ├→ restoring → cancelled (moved back to Drafts)
 *      └→ cancelled
 *
 * Every change is a conditional transition, so two processes can never both
 * claim the same job.
 */
interface QueueInterface
{
    public const PENDING    = 'pending';
    public const SUBMITTING = 'submitting';
    public const ACCEPTED   = 'accepted';
    public const DONE       = 'done';
    public const RESTORING  = 'restoring';
    public const CANCELLED  = 'cancelled';
    public const FAILED     = 'failed';
    public const UNCERTAIN  = 'uncertain';

    /**
     * @param array $job Column => value
     *
     * @return int New job ID
     */
    public function add(array $job): int;

    /**
     * @param int|null $userId Restrict to this owner
     */
    public function find(int $id, ?int $userId = null): ?array;

    /**
     * Jobs of one user without message content, newest send time first.
     */
    public function listForUser(int $userId): array;

    /**
     * Atomically move a job from one status to another.
     *
     * @param array    $set    Additional columns to update
     * @param int|null $userId Only if the job belongs to this user
     *
     * @return bool True if this call changed the job
     */
    public function transition(int $id, string $from, string $to, array $set = [], ?int $userId = null): bool;

    /**
     * @return int[] IDs of pending jobs due at $now, oldest first
     */
    public function dueIds(\DateTimeImmutable $now, int $limit): array;

    /**
     * @return int[] IDs of jobs in $status whose lease ran out before $now
     */
    public function expiredLeaseIds(string $status, \DateTimeImmutable $now): array;

    /**
     * Delete finished jobs last changed before $before.
     *
     * @return int Number of deleted jobs
     */
    public function purge(\DateTimeImmutable $before): int;
}
