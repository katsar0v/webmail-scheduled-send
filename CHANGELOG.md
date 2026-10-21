# Changelog

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
