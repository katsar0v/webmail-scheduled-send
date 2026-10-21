# webmail-scheduled-send

"Send later" for Roundcube webmail on Plesk: pick a date and time in the compose window, and the message goes out at that time, Gmail-style.

> **Status: planning.** There is no plugin code yet. A pre-check on a production Plesk server confirmed the two key building blocks: mail handed to Plesk's `sendmail` wrapper gets DKIM-signed, and `doveadm` can file the Sent copy without the user's password.

## Why another plugin?

The only existing option, [texxasrulez/scheduled_sending](https://github.com/texxasrulez/scheduled_sending), was archived in October 2026. It also:

- stores every user's SMTP password (encrypted) in the database so it can send while the user is offline, and
- triggers delivery through a web endpoint that is protected only by a token in the URL.

This project does both of those differently.

## Design principles

- **No stored user passwords.** A CLI worker on the mail server hands due messages to the local Postfix instance, and Dovecot's `doveadm` files the copy into the user's Sent folder. Neither step needs the user's credentials.
- **No web-reachable worker.** Delivery runs from a systemd timer or cron job, never over HTTP.
- **Roundcube builds the message.** The plugin hooks into the normal send action (`message_before_send`), so attachments, HTML, inline images and other plugins keep working. It stores the finished MIME message instead of submitting it.
- **Sender authorization is enforced by the plugin.** The From and envelope sender are checked against the user's real mailbox and aliases when the message is scheduled and again before it is sent.
- **At most once.** An explicit state machine (`pending → submitting → accepted → done`, plus `cancelled`, `failed`, `uncertain`) makes sure a crash never leads to a silent second delivery.

## Target environment

- Roundcube 1.6.x (Elastic skin), as shipped by Plesk (`plesk-roundcube`)
- Plesk Obsidian 18.0.x on Linux
- Postfix and Dovecot 2.4
- MySQL/MariaDB

## Planned features (MVP)

- "Send later" button next to "Send", with a date/time picker in the user's time zone
- List of scheduled messages: view, cancel, reschedule, or reopen as a draft to edit
- Sent copy plus the `\Answered` / `$Forwarded` flags on the original message after delivery
- Status and error notifications in Roundcube

## Documents

- [Feasibility analysis (German)](docs/feasibility-analysis.de.md): architecture options, Roundcube 1.6 hooks, risks, effort estimate

## License

[GPL-3.0](LICENSE)
