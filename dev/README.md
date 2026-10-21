# Development environment

Docker stack for working on the `scheduled_send` Roundcube plugin. The repo root
is mounted read-only into Roundcube as `plugins/scheduled_send`, so edits to the
plugin show up immediately (no rebuild; hard-reload the browser for JS/CSS).

| Service     | Image                                     | Purpose                                  |
|-------------|-------------------------------------------|------------------------------------------|
| `roundcube` | `roundcube/roundcubemail:1.6.19-apache`   | Webmail, http://localhost:8095           |
| `db`        | `mariadb:10.11.19`                        | Roundcube database (MySQL in production) |
| `mail`      | `greenmail/standalone:2.1.14`             | Plain IMAP (3143) and SMTP (3025)        |

Requirements: Docker with the Compose plugin, `curl`.

## Usage

```bash
cd dev
./dev.sh up        # start everything, wait for HTTP 200 on http://localhost:8095
./dev.sh db-init   # load ../SQL/mysql.initial.sql (skipped if the file does not exist)
./dev.sh worker    # php bin/worker.php inside the roundcube container; args are passed on
./dev.sh logs      # follow logs, e.g. ./dev.sh logs roundcube
./dev.sh shell     # bash in the roundcube container
./dev.sh down      # stop and delete containers AND volumes (database is wiped)
```

`up` takes about 25 seconds on a fresh start. The image prints a "not empty"
warning and sleeps 10 seconds because the plugin mount creates
`/var/www/html/plugins` before Roundcube is copied in. That is harmless.
`docker compose restart roundcube` skips the sleep.

`db-init` only loads the plugin schema. Roundcube's own tables are created by
the image on start. `db-init` is not idempotent unless the SQL file is; run
`./dev.sh down && ./dev.sh up` for a clean database.

## Test login

GreenMail runs with authentication disabled: any user and any password work, and
the mailbox is created on first login or first delivery. Use the full address as
the username.

| Username            | Password |
|---------------------|----------|
| `alice@example.test`| anything |
| `bob@example.test`  | anything |

Send a mail from alice to `bob@example.test`, then log in as bob (in a private
window) to see it. From the host you can also use IMAP directly on
`127.0.0.1:3143` (SMTP on `127.0.0.1:3025`). Both are bound to loopback only.

## Configuration

- Plugins are enabled through `ROUNDCUBEMAIL_PLUGINS` in `docker-compose.yml`:
  `scheduled_send,managesieve`. There is no Sieve server in the stack, so
  managesieve only shows "Unable to connect to server" in Settings > Filters;
  mail and compose are not affected.
- Plugin settings for dev live in `config/scheduled_send.inc.php`. The image
  appends `include('/var/roundcube/config/*.php')` to its generated Roundcube
  config at every start, so every `*.php` file in `dev/config/` is merged into
  `$config`. Edits to an existing file are read on the next request; a new
  `*.php` file in `dev/config/` needs `docker compose restart roundcube`
  (the include list is built at container start).
  Do not create a `config.inc.php` in the plugin directory while developing:
  Roundcube merges a plugin's own config over the main config, so it would win
  over `dev/config/`.
- The dev config delivers over SMTP (`mail:3025`) and stores sent copies via IMAP
  APPEND, because the production tools (`/usr/sbin/sendmail`, `doveadm`) do not
  exist here.

## Running without the plugin

If `plugins/scheduled_send/scheduled_send.php` does not exist (yet), Roundcube
does not fail: it logs `Failed to load plugin file ...` on every request and keeps
working without the plugin. To get a clean log while the plugin code is incomplete
or broken, start the stack without it:

```bash
DEV_PLUGINS=managesieve ./dev.sh up
```

`DEV_PLUGINS` replaces the whole plugin list (comma separated). Run `./dev.sh down`
first if the stack is already running with a different list, or recreate just the
web container with `DEV_PLUGINS=managesieve docker compose up -d roundcube`.

## Troubleshooting

- Port 8095, 3143 or 3025 already in use: stop the other service, or change the
  left-hand side of the `ports:` entries in `docker-compose.yml`.
- Roundcube errors are written to the container log: `./dev.sh logs roundcube`.
- Inspect the merged configuration:
  `./dev.sh shell`, then `cat /var/www/html/config/config.docker.inc.php`.

## End-to-end test

`python3 e2e.py [screenshot-dir]` drives Roundcube with Playwright. It schedules, lists, edits and cancels messages, runs the worker and checks delivery, Bcc, the Sent copy and the Answered flag over IMAP. It needs `pip install playwright`. Set `BROWSER_PATH` to use an installed Chromium-based browser. It empties `scheduled_send_queue`.
