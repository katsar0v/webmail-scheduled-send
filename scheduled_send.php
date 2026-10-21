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
    public const VERSION = '0.1.0';

    public $task = 'mail|settings';

    /** @var rcmail */
    private $rc;

    /** @var QueueInterface|null */
    private $queue;

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
     * Stores the message instead of sending it, if the user picked "Send later".
     *
     * Runs inside the regular send action, so Roundcube has already validated the
     * form and built the complete MIME message, including attachments.
     */
    public function message_before_send($args)
    {
        $local = rcube_utils::get_input_string('_schedule_at', rcube_utils::INPUT_POST);

        if ($local === '' || !empty($args['abort']) || $this->rc->action != 'send'
            || !empty($_POST['_draft']) || !empty($_GET['_saveonly'])
        ) {
            return $args;
        }

        $this->add_texts('localization/');

        try {
            [$job, $sendAt] = $this->build_job($args, $local);
            $this->queue()->add($job);
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
        $message = $args['message'];
        $sendAt  = Schedule::parse(
            $local,
            rcube_utils::get_input_string('_schedule_tz', rcube_utils::INPUT_POST),
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
            'timezone'      => rcube_utils::get_input_string('_schedule_tz', rcube_utils::INPUT_POST),
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

        if ($status === QueueInterface::PENDING || $status === QueueInterface::FAILED) {
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
    public function action_restore()
    {
        $id     = (int) rcube_utils::get_input_string('_id', rcube_utils::INPUT_POST);
        $userId = (int) $this->rc->user->ID;
        $queue  = $this->queue();
        $job    = $queue->find($id, $userId);
        $drafts = $this->rc->config->get('drafts_mbox');

        if (!$job || !$drafts || !in_array($job['status'], [QueueInterface::PENDING, QueueInterface::FAILED], true)
            || !$queue->transition($id, $job['status'], QueueInterface::RESTORING, [], $userId)
        ) {
            $this->respond(false, null, 'cannotchange');
        }

        $draft   = MessageBuilder::forSentCopy($job['headers'], $job['body'],
            MessageBuilder::date(new DateTimeImmutable(), $job['timezone']), $job['bcc']);
        $storage = $this->rc->get_storage();

        // Like rcmail_sendmail::save_message(), create the folder if it is missing
        if ((!$storage->folder_exists($drafts) && !$storage->create_folder($drafts, true))
            || !$storage->save_message($drafts, $draft, '', false, ['SEEN'])
        ) {
            $queue->transition($id, QueueInterface::RESTORING, $job['status'], [], $userId);
            $this->respond(false, null, 'restorefailed');
        }

        $queue->transition($id, QueueInterface::RESTORING, QueueInterface::CANCELLED,
            ['headers' => '', 'body' => '', 'bcc' => null], $userId);

        $this->respond(true, 'restored', null);
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
