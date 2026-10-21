-- Scheduled send queue for Roundcube (MySQL/MariaDB).
-- Load into the Roundcube database after Roundcube's own schema.
-- If you use db_prefix, prefix both table names below accordingly.

CREATE TABLE IF NOT EXISTS `scheduled_send_queue` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int UNSIGNED NOT NULL,
  `username` varchar(128) NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'pending',
  `send_at` datetime NOT NULL,
  `timezone` varchar(64) NOT NULL,
  `envelope_from` varchar(255) NOT NULL,
  `recipients` text NOT NULL,
  `message_id` varchar(255) NOT NULL,
  `subject` varchar(255) NOT NULL DEFAULT '',
  `headers` longblob NOT NULL,
  `bcc` blob DEFAULT NULL,
  `body` longblob NOT NULL,
  `size` int UNSIGNED NOT NULL DEFAULT 0,
  `sent_mailbox` varchar(255) DEFAULT NULL,
  `flag_mailbox` varchar(255) DEFAULT NULL,
  `flag_message_id` varchar(255) DEFAULT NULL,
  `flag_name` varchar(16) DEFAULT NULL,
  `attempts` smallint UNSIGNED NOT NULL DEFAULT 0,
  `last_error` text DEFAULT NULL,
  `lease_until` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `submitted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  INDEX `scheduled_send_due_idx` (`status`, `send_at`),
  INDEX `scheduled_send_user_idx` (`user_id`, `send_at`),
  CONSTRAINT `scheduled_send_user_id_fk` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ROW_FORMAT=DYNAMIC ENGINE=InnoDB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
