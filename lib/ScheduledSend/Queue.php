<?php

namespace ScheduledSend;

/**
 * Queue stored in the Roundcube database (table scheduled_send_queue).
 */
class Queue implements QueueInterface
{
    private const COLUMNS = [
        'user_id', 'username', 'status', 'send_at', 'timezone', 'envelope_from', 'recipients',
        'message_id', 'subject', 'headers', 'bcc', 'body', 'size', 'sent_mailbox',
        'flag_mailbox', 'flag_message_id', 'flag_name', 'attempts', 'last_error',
        'lease_until', 'created_at', 'updated_at', 'submitted_at',
    ];

    private const LIST_COLUMNS = [
        'id', 'status', 'send_at', 'timezone', 'recipients', 'subject', 'size',
        'attempts', 'last_error', 'created_at', 'updated_at', 'submitted_at',
    ];

    /** Statuses that purge() may delete. */
    private const FINISHED = [self::DONE, self::CANCELLED, self::FAILED];

    /** @var \rcube_db */
    private $db;

    /** @var string */
    private $table;

    public function __construct(\rcube_db $db)
    {
        $this->db    = $db;
        $this->table = $db->table_name('scheduled_send_queue', true);
    }

    public static function datetime(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public function add(array $job): int
    {
        $now = self::datetime(new \DateTimeImmutable());
        $job += ['status' => self::PENDING, 'attempts' => 0, 'created_at' => $now, 'updated_at' => $now];
        $job  = $this->filter($job);

        $sql = sprintf('INSERT INTO %s (%s) VALUES (%s)',
            $this->table,
            implode(', ', array_map([$this->db, 'quote_identifier'], array_keys($job))),
            implode(', ', array_fill(0, count($job), '?'))
        );

        $this->query($sql, array_values($job));

        return (int) $this->db->insert_id('scheduled_send_queue');
    }

    public function find(int $id, ?int $userId = null): ?array
    {
        $sql    = "SELECT * FROM {$this->table} WHERE `id` = ?";
        $params = [$id];

        if ($userId !== null) {
            $sql     .= ' AND `user_id` = ?';
            $params[] = $userId;
        }

        $row = $this->db->fetch_assoc($this->query($sql, $params));

        return $row ?: null;
    }

    public function listForUser(int $userId): array
    {
        $columns = implode(', ', array_map([$this->db, 'quote_identifier'], self::LIST_COLUMNS));
        $result  = $this->query("SELECT $columns FROM {$this->table} WHERE `user_id` = ? ORDER BY `send_at` DESC", [$userId]);
        $rows    = [];

        while ($row = $this->db->fetch_assoc($result)) {
            $rows[] = $row;
        }

        return $rows;
    }

    public function usage(int $userId): array
    {
        $sql = sprintf('SELECT COUNT(*) AS `count`, COALESCE(SUM(`size`), 0) AS `bytes` FROM %s WHERE `user_id` = ? AND `status` IN (%s)',
            $this->table, $this->db->array2list(self::HOLDING_CONTENT));

        $row = $this->db->fetch_assoc($this->query($sql, [$userId]));

        return ['count' => (int) $row['count'], 'bytes' => (int) $row['bytes']];
    }

    public function findByMessageId(int $userId, string $messageId, array $statuses): ?array
    {
        $sql = sprintf('SELECT * FROM %s WHERE `user_id` = ? AND `message_id` = ? AND `status` IN (%s) ORDER BY `id` DESC',
            $this->table, $this->db->array2list($statuses));

        $row = $this->db->fetch_assoc($this->db->limitquery($sql, 0, 1, $userId, $messageId));

        return $row ?: null;
    }

    public function transition(int $id, string $from, string $to, array $set = [], ?int $userId = null): bool
    {
        $set = $this->filter(['status' => $to, 'updated_at' => self::datetime(new \DateTimeImmutable())] + $set);

        $assignments = implode(', ', array_map(function ($column) {
            return $this->db->quote_identifier($column) . ' = ?';
        }, array_keys($set)));

        $sql    = "UPDATE {$this->table} SET $assignments WHERE `id` = ? AND `status` = ?";
        $params = array_merge(array_values($set), [$id, $from]);

        if ($userId !== null) {
            $sql     .= ' AND `user_id` = ?';
            $params[] = $userId;
        }

        return $this->db->affected_rows($this->query($sql, $params)) === 1;
    }

    public function dueIds(\DateTimeImmutable $now, int $limit): array
    {
        $sql    = "SELECT `id` FROM {$this->table} WHERE `status` = ? AND `send_at` <= ? ORDER BY `send_at`, `id`";
        $result = $this->db->limitquery($sql, 0, $limit, self::PENDING, self::datetime($now));

        return $this->ids($result);
    }

    public function expiredLeaseIds(string $status, \DateTimeImmutable $now): array
    {
        $sql = "SELECT `id` FROM {$this->table} WHERE `status` = ? AND `lease_until` < ? ORDER BY `id`";

        return $this->ids($this->query($sql, [$status, self::datetime($now)]));
    }

    public function purge(\DateTimeImmutable $before): int
    {
        $sql = sprintf('DELETE FROM %s WHERE `status` IN (%s) AND `updated_at` < ?',
            $this->table, $this->db->array2list(self::FINISHED));

        return (int) $this->db->affected_rows($this->query($sql, [self::datetime($before)]));
    }

    private function ids($result): array
    {
        $ids = [];

        while ($row = $this->db->fetch_assoc($result)) {
            $ids[] = (int) $row['id'];
        }

        return $ids;
    }

    private function filter(array $columns): array
    {
        $unknown = array_diff(array_keys($columns), self::COLUMNS);

        if (!empty($unknown)) {
            throw new \InvalidArgumentException('Unknown queue columns: ' . implode(', ', $unknown));
        }

        return $columns;
    }

    private function query(string $sql, array $params)
    {
        $result = $this->db->query($sql, $params);

        if ($error = $this->db->is_error($result)) {
            throw new \RuntimeException("Queue query failed: $error");
        }

        return $result;
    }
}
