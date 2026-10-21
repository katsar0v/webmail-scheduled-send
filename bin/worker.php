#!/usr/bin/env php
<?php

/**
 * Delivers due scheduled messages. Run it every minute, e.g. from cron:
 *
 *   * * * * * rc-scheduler php /path/to/roundcube/plugins/scheduled_send/bin/worker.php
 *
 * Set ROUNDCUBE_PATH if the plugin is not installed in <roundcube>/plugins/scheduled_send.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$roundcube = getenv('ROUNDCUBE_PATH') ?: __DIR__ . '/../../..';

define('INSTALL_PATH', realpath($roundcube) . '/');

require INSTALL_PATH . 'program/include/clisetup.php';
require __DIR__ . '/../lib/autoload.php';

use ScheduledSend\Queue;
use ScheduledSend\SenderPolicy;
use ScheduledSend\Store;
use ScheduledSend\Transport;
use ScheduledSend\Worker;

$rcube  = rcube::get_instance();
$config = $rcube->config;

// Same lookup as rcube_plugin::load_config(); defaults are in the code below
if (is_file(__DIR__ . '/../config.inc.php')) {
    $config->load_from_file(__DIR__ . '/../config.inc.php');
}
else {
    $config->load_from_file('scheduled_send.inc.php');
}

$log = static function (string $message): void {
    rcube::write_log('scheduled_send', $message);
};

$lockFile = $config->get('scheduled_send_lock_file') ?: rtrim($config->get('temp_dir', sys_get_temp_dir()), '/') . '/scheduled_send.lock';
$lock     = fopen($lockFile, 'c');

if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    // Another worker is still running
    exit(0);
}

function scheduled_send_host(string $value, int $defaultPort): array
{
    $parts = explode(':', $value, 2);

    return [$parts[0], isset($parts[1]) ? (int) $parts[1] : $defaultPort];
}

switch ($config->get('scheduled_send_transport', 'sendmail')) {
    case 'smtp':
        [$host, $port] = scheduled_send_host($config->get('scheduled_send_smtp_host', 'localhost:25'), 25);
        $transport     = new Transport\SmtpTransport($host, $port);
        break;

    case 'sendmail':
        $transport = new Transport\SendmailTransport((array) $config->get('scheduled_send_sendmail_command', ['/usr/sbin/sendmail']));
        break;

    default:
        fwrite(STDERR, "Unknown scheduled_send_transport\n");
        exit(1);
}

switch ($config->get('scheduled_send_sent_store', 'doveadm')) {
    case 'doveadm':
        $store = new Store\DoveadmStore((array) $config->get('scheduled_send_doveadm_command', ['/usr/bin/doveadm']));
        break;

    case 'imap':
        [$host, $port] = scheduled_send_host($config->get('scheduled_send_imap_host', 'localhost:143'), 143);
        $store         = new Store\ImapStore($host, $port, (string) $config->get('scheduled_send_imap_password', ''));
        break;

    case 'none':
        $store = new Store\NullStore();
        break;

    default:
        fwrite(STDERR, "Unknown scheduled_send_sent_store\n");
        exit(1);
}

$db     = $rcube->get_dbh();
$policy = $config->get('scheduled_send_sender_policy', SenderPolicy::LOGIN);

$senderAllowed = static function (array $job) use ($db, $policy): bool {
    $identities = [];

    if ($policy === SenderPolicy::IDENTITIES) {
        $result = $db->query('SELECT `email` FROM ' . $db->table_name('identities', true)
            . ' WHERE `user_id` = ? AND `del` <> 1', $job['user_id']);

        while ($row = $db->fetch_assoc($result)) {
            $identities[] = $row['email'];
        }
    }

    return SenderPolicy::permits([$job['envelope_from']], SenderPolicy::allowed($policy, $job['username'], $identities));
};

$worker = new Worker(new Queue($db), $transport, $store, $senderAllowed, $log, [
    'max_attempts'   => (int) $config->get('scheduled_send_max_attempts', 5),
    'retry_delay'    => (int) $config->get('scheduled_send_retry_delay', 300),
    'retention_days' => (int) $config->get('scheduled_send_retention_days', 7),
]);

$stats = $worker->run(new DateTimeImmutable());

if (array_sum($stats) > 0) {
    $log('run: ' . http_build_query($stats, '', ' '));
}

flock($lock, LOCK_UN);
