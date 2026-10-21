# Changelog

## 0.1.1 – 2026-10-21

Fixes from the first code review, plus a Bulgarian translation.

- **Sent copy survives failures.** If the Sent copy or the Answered/Forwarded flag fails after delivery (e.g. a full mailbox), the job keeps its content and the worker retries for up to 24 hours. Each step runs only once, and the message is never sent again.
- **Per-user limits:** at most 50 waiting messages and 100 MB per user (`scheduled_send_max_pending`, `scheduled_send_max_pending_bytes`). Scheduling also updates Roundcube's `sendmail_delay` bookkeeping.
- **Plugin order:** the message is captured after all other plugins' `message_before_send` handlers, so headers they add or a rejection they make apply to scheduled messages too.
- **Encryption (Mailvelope, Enigma):** these submit the form later, after encryption or a key password prompt. Such a submission now stays scheduled instead of going out immediately. A plain "Send" still clears any earlier schedule.
- **No duplicates:** a retried scheduling request (e.g. after a timeout) does not queue the same message twice.
- **Stuck "Edit":** an "Edit" interrupted mid-way can be retried after 5 minutes without creating a second draft.
- **Replies and forwards:** an edited scheduled reply or forward keeps Roundcube's `X-Draft-Info`, so the original is still flagged when it goes out.
- `no_save_sent_messages` is respected.
- **sendmail and doveadm** run with non-blocking I/O and a deadline (120 s and 60 s). A sendmail that times out after reading the whole message makes the job "uncertain" instead of retrying it.
- **Development SMTP transport:** a lost reply after DATA counts as "uncertain".
- **Time zones:** browser names like `Europe/Kiev` or `Asia/Calcutta` are accepted and stored under their current name. In the repeated hour when daylight saving time ends, the first occurrence is used, as documented.
- Bulgarian translation. The UI follows each user's webmail language, with English as the fallback.

## 0.1.0 – 2026-10-21

First version.

- "Send later" button next to "Send" in the compose screen. It opens a date/time picker with quick choices (tomorrow morning, tomorrow afternoon, Monday morning) and shows the browser's time zone.
- The message is built by Roundcube's normal send action, so attachments, HTML, inline images and Bcc work. It is then stored in the database instead of being sent.
- CLI worker (`bin/worker.php`, run every minute) delivers due messages through `sendmail` (production) or SMTP (development).
  - It files the Sent copy and sets the Answered/Forwarded flag via `doveadm` (production) or IMAP (development).
  - No user passwords are stored, and there is no web-reachable worker endpoint.
- Settings → "Scheduled messages": list with status. "Edit" moves a message back to Drafts, "Cancel" deletes it.
- Sender check: the envelope sender and From must be the login address (default) or one of the user's identities.
- At-most-once delivery: if the worker dies during submission, the job is marked "uncertain" and is never resent automatically. Temporary errors are retried with a growing delay.
- English and German translations.
