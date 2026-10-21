<?php

/**
 * Dev-only settings for the scheduled_send plugin.
 *
 * Loaded by the Roundcube Docker image: its entrypoint appends
 * include('/var/roundcube/config/*.php') to config/config.docker.inc.php, so
 * the $config keys below are merged into Roundcube's main configuration.
 * (Use $config[...] assignments here; a bare `return [...]` would be ignored.)
 *
 * All keys and their defaults are documented in config.inc.php.dist at the
 * repo root. Do not put a config.inc.php into the plugin directory while developing: a
 * plugin's own config file overrides keys set here.
 */

// Dev has no Plesk sendmail/doveadm: deliver over SMTP, store copies via IMAP.
$config['scheduled_send_transport'] = 'smtp';
$config['scheduled_send_smtp_host'] = 'mail:3025';
$config['scheduled_send_sent_store'] = 'imap';
$config['scheduled_send_imap_host'] = 'mail:3143';
$config['scheduled_send_imap_password'] = 'dev'; // GreenMail accepts any password
