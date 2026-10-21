# webmail-scheduled-send

"Send later" for Roundcube webmail on Plesk: pick a date and time in the compose window, and the message goes out at that time, Gmail-style.

**Version 0.1.1** · Roundcube 1.6 (Elastic skin) · PHP 7.4+ · MySQL/MariaDB · [Changelog](CHANGELOG.md)

## How it works

1. **Schedule.** The compose screen gets a **Send later** button next to **Send**. The user picks a time.
2. **Store.** Roundcube builds the message exactly as for a normal send (attachments, HTML, inline images, Bcc). The plugin stores the finished MIME message in the database instead of sending it.
3. **Deliver.** A CLI worker runs every minute and hands due messages to the local MTA through `sendmail`.
4. **File.** The worker files the Sent copy with `doveadm` and marks the original message as Answered or Forwarded.
5. **Manage.** Under **Settings → Scheduled messages**, users see their queue. **Edit** puts a message back into Drafts; **Cancel** deletes it.

## Why another plugin?

The only existing option, [texxasrulez/scheduled_sending](https://github.com/texxasrulez/scheduled_sending), was archived in October 2026. It also:

- stores every user's SMTP password (encrypted) so it can send while the user is offline, and
- triggers delivery through a web endpoint protected only by a token in the URL.

This plugin does neither:

- **No stored passwords.** `sendmail` and `doveadm` don't need the user's credentials.
- **No web-reachable worker.** Delivery runs from cron or a systemd timer only.
- **Sender check.** The envelope sender and From must be the user's login address (or, if configured, one of their identities). The plugin checks this when the message is scheduled and again right before sending. Neither Plesk's sendmail wrapper nor its default Postfix setup checks the sender.
- **At most once.** If the worker dies while handing a message to the MTA, or sendmail stops responding after reading it, the job is marked *uncertain* and is never resent automatically. Temporary errors are retried with a growing delay. A failed Sent copy is retried separately without resending.
- **Limits per user.** Each user can have at most 50 waiting messages and 100 MB in the queue (configurable), so one mailbox cannot fill the shared database.

## Installation

### 1. Files

Put the plugin into `<roundcube>/plugins/scheduled_send/`. On Plesk that is `/usr/share/psa-roundcube/plugins/scheduled_send/`. The directory name must be `scheduled_send`.

```sh
curl -L https://github.com/katsar0v/webmail-scheduled-send/archive/refs/tags/v0.1.0.tar.gz \
  | tar xz --strip-components=1 -C /usr/share/psa-roundcube/plugins/scheduled_send
```

`dev/`, `docs/` and `tests/` are not needed on a server.

### 2. Database

Load `SQL/mysql.initial.sql` into the Roundcube database. On Plesk the database is `roundcubemail`:

```sh
MYSQL_PWD="$(cat /etc/psa/.psa.shadow)" mysql -uadmin roundcubemail < SQL/mysql.initial.sql
```

If Roundcube uses a `db_prefix`, adjust the table names in the file first.

### 3. Configuration

Copy `config.inc.php.dist` to `config.inc.php` in the plugin directory and adjust it. Every option is documented in that file.

On Plesk, the worker runs as Roundcube's system user and uses `sudo` to run `sendmail` and `doveadm` as `popuser`. `popuser` can open Dovecot's `auth-userdb` socket, and Plesk's outgoing mail control accepts sendmail from it. Use these settings:

```php
$config['scheduled_send_transport'] = 'sendmail';
$config['scheduled_send_sendmail_command'] = ['/usr/bin/sudo', '-n', '-u', 'popuser', '/usr/sbin/sendmail'];
$config['scheduled_send_sent_store'] = 'doveadm';
$config['scheduled_send_doveadm_command'] = ['/usr/bin/sudo', '-n', '-u', 'popuser', '/usr/bin/doveadm'];
```

Add a matching sudo rule in `/etc/sudoers.d/roundcube-scheduled-send`, replacing `roundcube_sysuser` with the user that runs the worker:

```
roundcube_sysuser ALL=(popuser) NOPASSWD: /usr/sbin/sendmail, /usr/bin/doveadm save *, /usr/bin/doveadm flags add *
```

### 4. Enable the plugin

Add `scheduled_send` to `$config['plugins']` in Roundcube's configuration. On Plesk, use `/usr/share/psa-roundcube/config/config.local.php`, which survives updates. Older Plesk installations may still have a `config.inc.php` that does not include `config.local.php`. Compare it with `config.inc.php.dpkg-dist` and add the include block if it is missing. List all plugins you want, because the list replaces Plesk's default one.

### 5. Worker

Run `bin/worker.php` every minute as a user that can read Roundcube's configuration and write its log directory, e.g. `/etc/cron.d/roundcube-scheduled-send`:

```
* * * * * roundcube_sysuser /usr/bin/php /usr/share/psa-roundcube/plugins/scheduled_send/bin/worker.php
```

The worker logs to Roundcube's log as `scheduled_send`. Only one worker runs at a time; a lock file prevents overlap. Set `ROUNDCUBE_PATH` if the plugin is not installed in `<roundcube>/plugins/scheduled_send`.

## Limitations (0.1.0)

- MySQL/MariaDB only.
- Elastic skin only.
- No delivery status notification (DSN) requests.
- The whole message is stored in the database. The default size limit is 10 MB (`scheduled_send_max_size`). The per-user limits are checked before each new message, so two simultaneous requests can exceed them by one message.
- Replies and forwards of a single message get the Answered/Forwarded flag. Forwarding several messages at once does not.
- An ambiguous local time (the repeated hour when daylight saving time ends) uses the first occurrence.
- The UI is available in English, German and Bulgarian. Other languages fall back to English.

## Development

`dev/` contains a Docker setup with Roundcube 1.6.19, MariaDB and GreenMail; see [dev/README.md](dev/README.md). Unit tests:

```sh
composer install
vendor/bin/phpunit
```

[Feasibility analysis (German)](docs/feasibility-analysis.de.md): architecture options, Roundcube hooks, risks.

## License

[GPL-3.0-or-later](LICENSE)
