<?php

/**
 * Minimal PSR-4 autoloader for the ScheduledSend namespace, so the plugin
 * works without running Composer inside the Roundcube installation.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'ScheduledSend\\';

    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $file = __DIR__ . '/ScheduledSend/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (is_file($file)) {
        require $file;
    }
});
