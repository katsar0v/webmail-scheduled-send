<?php

/**
 * Scheduled send ("send later") for Roundcube.
 *
 * The compose screen gets a "Send later" button. The finished message is
 * stored in the database instead of being sent; bin/worker.php delivers it
 * at the chosen time through the local MTA, without the user's password.
 *
 * @license GNU GPLv3+
 */

require_once __DIR__ . '/lib/autoload.php';

use ScheduledSend\InvalidInput;
use ScheduledSend\MessageBuilder;
use ScheduledSend\Queue;
use ScheduledSend\QueueInterface;
use ScheduledSend\Schedule;
use ScheduledSend\SenderPolicy;
use ScheduledSend\Store\StoreInterface;

class scheduled_send extends rcube_plugin
{
    public const VERSION = '0.1.1';

    /** Seconds after which an interrupted "Edit" (restore to Drafts) may be retried */
    private const RESTORE_TIMEOUT = 300;

    public $task = 'mail|settings';

    /** @var rcmail */
    private $rc;

    /** @var QueueInterface|null */
    private $queue;

    /** @var bool capture() is registered for this request */
    private $capturing = false;

    /**
     * Shown on Roundcube's "About" page.
     */
    public static function info()
    {
        return [
            'name'    => 'scheduled_send',
            'vendor'  => 'Katsarov Design',
            'version' => self::VERSION,
            'license' => 'GPL-3.0-or-later',
            'uri'     => 'https://github.com/katsar0v/webmail-scheduled-send',
        ];
    }

    public function init()
    {
        $this->rc = rcmail::get_instance();

        // Defaults live in the code; config.inc.php.dist only documents them, so it is
        // not loaded (it would override settings made in Roundcube's main config).
        $this->load_config();

        if ($this->rc->task == 'mail') {
            $this->add_hook('message_before_send', [$this, 'message_before_send']);

            if ($this->rc->action == 'compose') {
                $this->add_texts('localization/', true);
                $this->include_script('js/scheduled_send.js');
                $this->include_stylesheet($this->local_skin_path() . '/scheduled_send.css');
            }
        }
        else if ($this->rc->task == 'settings') {
            $this->add_texts('localization/', true);
            $this->add_hook('settings_actions', [$this, 'settings_actions']);
            $this->register_action('plugin.scheduled_send', [$this, 'action_list']);
            $this->register_action('plugin.scheduled_send.cancel', [$this, 'action_cancel']);
            $this->register_action('plugin.scheduled_send.restore', [$this, 'action_restore']);
            $this->include_script('js/scheduled_send.js');
            $this->include_stylesheet($this->local_skin_path() . '/scheduled_send.css');
        }
    }

    /**
     * Handles "Send later" inside the regular send action, where Roundcube has
     * already validated the form and built the complete MIME message.
     *
     * Plugins loaded after this one may still change or reject the message in
     * their own message_before_send handlers. So this only appends capture()
     * to the end of the handler list; Roundcube's hook loop also runs handlers
     * added while the hook is running.
     */
    public function message_before_send($args)
    {
        if (!$this->capturing && $this->is_schedule_request()) {
            $this->capturing = true;
            $this->add_hook('message_before_send', [$this, 'capture']);
        }

        return $args;
    }

    private function is_schedule_request(): bool
    {
        return $this->rc->action == 'send'
            && rcube_utils::get_input_string('_schedule_at', rcube_utils::INPUT_POST) !== ''
            && empty($_POST['_draft'])
            && empty($_GET['_saveonly']);
    }

    /**
     * Last message_before_send handler: stores the final message instead of sending it.
     */
    public function capture($args)
    {
        // Another plugin rejected the message (or sent it itself): let Roundcube handle that as usual
        if (!empty($args['abort'])) {
            return $args;
        }

        $this->add_texts('localization/');

        try {
            [$job, $sendAt] = $this->build_job($args, rcube_utils::get_input_string('_schedule_at', rcube_utils::INPUT_POST));

            // A retried request (e.g. after a timeout) must not schedule the same message twice
            $existing = $this->queue()->findByMessageId($job['user_id'], $job['message_id'], [
                QueueInterface::PENDING, QueueInterface::SUBMITTING, QueueInterface::ACCEPTED, QueueInterface::DONE,
            ]);

            if ($existing) {
                $sendAt = new DateTimeImmutable($existing['send_at'], new DateTimeZone('UTC'));
            }
            else {
                $this->check_quota($job);
                $this->queue()->add($job);
            }

            // Keep Roundcube's flood protection (sendmail_delay) working for scheduled messages
            if ($this->rc->config->get('sendmail_delay')) {
                $this->rc->user->save_prefs(['last_message_time' => time()]);
            }
        }
        catch (InvalidInput $e) {
            $this->send_error($this->gettext($e->getMessage()));
        }
        catch (Exception $e) {
            rcube::raise_error([
                    'code' => 500, 'file' => __FILE__, 'line' => __LINE__,
                    'message' => 'scheduled_send: ' . $e->getMessage(),
                ], true, false
            );

            $this->send_error($this->gettext('schedulefailed'));
        }

        $this->finish_compose($sendAt);
    }

    /**
     * @return array{0: array, 1: DateTimeImmutable}
     */
    private function build_job(array $args, string $local): array
    {
        /** @var Mail_mime $message */
        $message  = $args['message'];
        $timezone = Schedule::zone(rcube_utils::get_input_string('_schedule_tz', rcube_utils::INPUT_POST))->getName();
        $sendAt   = Schedule::parse(
            $local,
            $timezone,
            new DateTimeImmutable(),
            (int) $this->rc->config->get('scheduled_send_min_lead', 60),
            (int) $this->rc->config->get('scheduled_send_max_horizon', 31536000)
        );

        $headers = $message->headers();
        $from    = (string) $args['from'];
        $login   = (string) $this->rc->get_user_name();

        // Envelope sender and every From address must belong to this user
        $fromHeader = array_values(rcube_mime::decode_address_list($headers['From'] ?? '', null, true, null, true));
        $allowed    = SenderPolicy::allowed(
            $this->rc->config->get('scheduled_send_sender_policy', SenderPolicy::LOGIN),
            $login,
            array_column($this->rc->user->list_emails(), 'email')
        );

        if (!SenderPolicy::permits(array_merge([$from], $fromHeader), $allowed)) {
            throw new InvalidInput('sendernotallowed');
        }

        $recipients = $this->recipients(array_merge((array) $args['mailto'], [$headers['Cc'] ?? '', $headers['Bcc'] ?? '']));

        if (empty($recipients)) {
            throw new InvalidInput('norecipients');
        }

        // Same order as rcube::deliver_message(): headers first, then the body
        $headerText = $message->txtHeaders(['Bcc' => null, 'Date' => null], true);
        $body       = $message->get();

        if (!is_string($body)) {
            throw new RuntimeException('Could not build the message body');
        }

        $size = strlen($headerText) + strlen($body);

        if ($size > (int) $this->rc->config->get('scheduled_send_max_size', 10485760)) {
            throw new InvalidInput('messagetoolarge');
        }

        $compose = $this->compose_data();
        $subject = rcube_mime::decode_header($headers['Subject'] ?? '');

        $job = [
            'user_id'       => (int) $this->rc->user->ID,
            'username'      => $login,
            'send_at'       => Queue::datetime($sendAt),
            'timezone'      => $timezone,
            'envelope_from' => $from,
            'recipients'    => json_encode($recipients),
            'message_id'    => (string) ($headers['Message-ID'] ?? ''),
            'subject'       => mb_substr($subject, 0, 255),
            'headers'       => $headerText,
            'bcc'           => $headers['Bcc'] ?? null,
            'body'          => $body,
            'size'          => $size,
            'sent_mailbox'  => $this->sent_mailbox(),
        ];

        return [$job + $this->flag_target($compose), $sendAt];
    }

    /**
     * Per-user limits, so one mailbox cannot fill the shared database.
     */
    private function check_quota(array $job): void
    {
        $usage    = $this->queue()->usage($job['user_id']);
        $maxCount = (int) $this->rc->config->get('scheduled_send_max_pending', 50);
        $maxBytes = (int) $this->rc->config->get('scheduled_send_max_pending_bytes', 100 * 1024 * 1024);

        if (($maxCount > 0 && $usage['count'] >= $maxCount)
            || ($maxBytes > 0 && $usage['bytes'] + $job['size'] > $maxBytes)
        ) {
            throw new InvalidInput('quotaexceeded');
        }
    }

    /**
     * @return string[] Plain recipient addresses
     */
    private function recipients(array $lists): array
    {
        $addresses = rcube_mime::decode_address_list(implode(', ', array_filter($lists)), null, true, null, true);
        $addresses = array_filter(array_map('trim', $addresses), function ($address) {
            return strpos($address, '@') !== false;
        });

        return array_values(array_unique($addresses));
    }

    /**
     * Folder for the Sent copy, chosen like rcmail_sendmail::save_message() does.
     */
    private function sent_mailbox(): ?string
    {
        if ($this->rc->config->get('no_save_sent_messages')) {
            return null;
        }

        $target = isset($_POST['_store_target'])
            ? rcube_utils::get_input_string('_store_target', rcube_utils::INPUT_POST, true)
            : $this->rc->config->get('sent_mbox');

        if (!$target) {
            return null;
        }

        $storage = $this->rc->get_storage();

        if (!$storage->folder_exists($target) && !$storage->create_folder($target, true)) {
            throw new RuntimeException("Could not create folder $target");
        }

        return $target;
    }

    /**
     * The original message of a reply or forward, to flag it once the reply goes out.
     */
    private function flag_target(array $compose): array
    {
        if (!empty($compose['reply_uid'])) {
            $uid       = $compose['reply_uid'];
            $flag      = StoreInterface::ANSWERED;
            // Set by Roundcube from the full original message (also used for In-Reply-To)
            $messageId = $compose['reply_msgid'] ?? null;
        }
        else if (!empty($compose['forward_uid'])) {
            $uid       = $compose['forward_uid'];
            $flag      = StoreInterface::FORWARDED;
            $messageId = null;
        }
        else {
            return [];
        }

        // Only a single, plain UID (not a forwarded set or an attached message part)
        if (!preg_match('/^\d+$/', (string) $uid) || empty($compose['mailbox'])) {
            return [];
        }

        if (!$messageId) {
            // Roundcube's default header fetch leaves out Message-ID, so ask for it explicitly
            $storage = $this->rc->get_storage();
            $storage->check_connection();
            $original  = $storage->conn->fetchHeader($compose['mailbox'], $uid, true, false, ['MESSAGE-ID']);
            $messageId = $original ? $original->messageID : null;
        }

        // "mid:<n>" is Roundcube's placeholder for a missing Message-ID
        if (!$messageId || strpos($messageId, 'mid:') === 0) {
            return [];
        }

        return [
            'flag_mailbox'    => $compose['mailbox'],
            'flag_message_id' => $messageId,
            'flag_name'       => $flag,
        ];
    }

    private function compose_data(): array
    {
        $id = rcube_utils::get_input_string('_id', rcube_utils::INPUT_GPC);

        return $_SESSION['compose_data_' . $id] ?? [];
    }

    /**
     * Ends the send request like a successful send: drops the old draft and the
     * compose session, then lets the client close the compose screen.
     */
    private function finish_compose(DateTimeImmutable $sendAt): void
    {
        $composeId = rcube_utils::get_input_string('_id', rcube_utils::INPUT_GPC);
        $drafts    = $this->rc->config->get('drafts_mbox');
        $draftUid  = rcube_utils::get_input_string('_draft_saveid', rcube_utils::INPUT_POST);
        $folders   = [];

        if ($draftUid && $drafts) {
            $this->rc->get_storage()->delete_message($draftUid, $drafts);
            $folders[] = $drafts;
        }

        $this->rc->plugins->exec_hook('attachments_cleanup', ['group' => $composeId]);
        $this->rc->session->remove('compose_data_' . $composeId);
        $_SESSION['last_compose_session'] = $composeId;

        $this->rc->output->command('remove_compose_data', $composeId);
        $this->rc->output->command('sent_successfully', 'confirmation',
            $this->gettext(['name' => 'scheduledfor', 'vars' => ['date' => $this->rc->format_date($sendAt)]]),
            $folders, false
        );

        $this->rc->output->send('iframe');
    }

    private function send_error(string $message): void
    {
        $this->rc->output->show_message($message, 'error');
        $this->rc->output->send('iframe');
    }

    public function settings_actions($args)
    {
        $args['actions'][] = [
            'action' => 'plugin.scheduled_send',
            'class'  => 'scheduled-send',
            'label'  => 'scheduledmessages',
            'title'  => 'scheduledmessages',
            'domain' => 'scheduled_send',
        ];

        return $args;
    }

    public function action_list()
    {
        $this->register_handler('plugin.body', [$this, 'render_list']);
        $this->rc->output->set_pagetitle($this->gettext('scheduledmessages'));
        $this->rc->output->send('plugin');
    }

    public function render_list(): string
    {
        $jobs = $this->queue()->listForUser((int) $this->rc->user->ID);

        if (empty($jobs)) {
            $content = html::p(['class' => 'scheduled-send-empty'], rcube::Q($this->gettext('noscheduled')));
        }
        else {
            $table = new html_table(['id' => 'scheduled-send-list', 'class' => 'table', 'cols' => 5]);

            foreach (['sendat', 'recipients', 'subject', 'status', ''] as $column) {
                $table->add_header($column, $column ? rcube::Q($this->gettext($column)) : '');
            }

            foreach ($jobs as $job) {
                $this->list_row($table, $job);
            }

            $content = $table->show();
        }

        $title = html::tag('h1', ['class' => 'voice'], rcube::Q($this->gettext('scheduledmessages')));

        return html::div(['class' => 'formcontent scheduled-send'], $title . $content);
    }

    private function list_row(html_table $table, array $job): void
    {
        $id         = (int) $job['id'];
        $recipients = implode(', ', json_decode($job['recipients'], true) ?: []);
        $status     = $job['status'];
        $actions    = '';

        if (self::restorable($job)) {
            $actions .= html::a([
                    'href' => '#', 'class' => 'btn btn-secondary btn-sm restore', 'data-id' => $id,
                    'title' => $this->gettext('restorehint'),
                ], rcube::Q($this->gettext('restore')));
        }

        if ($status === QueueInterface::PENDING) {
            $actions .= html::a(['href' => '#', 'class' => 'btn btn-secondary btn-sm cancel', 'data-id' => $id],
                rcube::Q($this->gettext('cancel')));
        }

        $statusText = rcube::Q($this->gettext('status_' . $status));

        if (!empty($job['last_error']) && $status !== QueueInterface::PENDING) {
            $statusText .= html::span(['class' => 'error-detail'], rcube::Q($job['last_error']));
        }

        $table->set_row_attribs(['data-id' => $id, 'class' => 'status-' . $status]);
        $table->add('sendat', rcube::Q($this->rc->format_date(new DateTimeImmutable($job['send_at'], new DateTimeZone('UTC')))));
        $table->add('recipients', rcube::Q($recipients));
        $table->add('subject', rcube::Q($job['subject']));
        $table->add('status', $statusText);
        $table->add('actions', $actions);
    }

    /**
     * Cancels a pending message for good.
     */
    public function action_cancel()
    {
        $id   = (int) rcube_utils::get_input_string('_id', rcube_utils::INPUT_POST);
        $done = $this->queue()->transition($id, QueueInterface::PENDING, QueueInterface::CANCELLED,
            ['headers' => '', 'body' => '', 'bcc' => null], (int) $this->rc->user->ID);

        $this->respond($done, 'cancelled', 'cannotchange');
    }

    /**
     * Cancels a pending (or failed) message and puts it back into Drafts for editing.
     */
    /**
     * Pending and failed messages can be moved back to Drafts. So can a restore
     * that was interrupted (e.g. the PHP process died) and has been stuck for
     * a few minutes.
     */
    private static function restorable(array $job): bool
    {
        if (in_array($job['status'], [QueueInterface::PENDING, QueueInterface::FAILED], true)) {
            return true;
        }

        return $job['status'] === QueueInterface::RESTORING
            && strtotime($job['updated_at'] . ' UTC') < time() - self::RESTORE_TIMEOUT;
    }

    /**
     * Cancels a pending (or failed) message and puts it back into Drafts for editing.
     */
    public function action_restore()
    {
        $id     = (int) rcube_utils::get_input_string('_id', rcube_utils::INPUT_POST);
        $userId = (int) $this->rc->user->ID;
        $queue  = $this->queue();
        $job    = $queue->find($id, $userId);
        $drafts = $this->rc->config->get('drafts_mbox');

        if (!$job || !$drafts || !self::restorable($job)
            || !$queue->transition($id, $job['status'], QueueInterface::RESTORING, [], $userId)
        ) {
            $this->respond(false, null, 'cannotchange');
        }

        $storage = $this->rc->get_storage();

        // Like rcmail_sendmail::save_message(), create the folder if it is missing
        if (!$storage->folder_exists($drafts) && !$storage->create_folder($drafts, true)) {
            $this->restore_failed($job);
        }

        // An interrupted earlier attempt may already have saved the draft
        $existing = $storage->search_once($drafts, 'HEADER MESSAGE-ID ' . rcube_imap_generic::escape($job['message_id']));

        if ($existing->is_empty()) {
            $draft = MessageBuilder::forDraft($job['headers'], $job['body'],
                MessageBuilder::date(new DateTimeImmutable(), $job['timezone']), $job['bcc'], $this->draft_info($job));

            if (!$storage->save_message($drafts, $draft, '', false, ['SEEN'])) {
                $this->restore_failed($job);
            }
        }

        $queue->transition($id, QueueInterface::RESTORING, QueueInterface::CANCELLED,
            ['headers' => '', 'body' => '', 'bcc' => null], $userId);

        $this->respond(true, 'restored', null);
    }

    private function restore_failed(array $job): void
    {
        // Back to where it was; a stuck restore stays "restoring" and can be retried later
        if ($job['status'] !== QueueInterface::RESTORING) {
            $this->queue()->transition((int) $job['id'], QueueInterface::RESTORING, $job['status'], [], (int) $job['user_id']);
        }

        $this->respond(false, null, 'restorefailed');
    }

    /**
     * Roundcube's X-Draft-Info for a scheduled reply or forward, so that sending
     * the edited draft still marks the original message as answered/forwarded.
     */
    private function draft_info(array $job): ?string
    {
        if (empty($job['flag_name']) || empty($job['flag_mailbox']) || empty($job['flag_message_id'])) {
            return null;
        }

        $found = $this->rc->get_storage()->search_once($job['flag_mailbox'],
            'HEADER MESSAGE-ID ' . rcube_imap_generic::escape($job['flag_message_id']));

        if ($found->is_empty()) {
            return null;
        }

        return rcmail_sendmail::draftinfo_encode([
            'type'   => $job['flag_name'] === StoreInterface::ANSWERED ? 'reply' : 'forward',
            'uid'    => $found->max(),
            'folder' => $job['flag_mailbox'],
        ]);
    }

    private function respond(bool $success, ?string $ok, ?string $error): void
    {
        if ($success) {
            $this->rc->output->show_message($this->gettext($ok), 'confirmation');
        }
        else {
            $this->rc->output->show_message($this->gettext($error), 'error');
        }

        $this->rc->output->command('plugin.scheduled_send.refresh');
        $this->rc->output->send();
    }

    private function queue(): QueueInterface
    {
        if (!$this->queue) {
            $this->queue = new Queue($this->rc->get_dbh());
        }

        return $this->queue;
    }
}
