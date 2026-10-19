<?php
// Invoked by the plugin installer on every boot; no network or array required.
declare(strict_types=1);
require_once dirname(__DIR__) . '/include/Icons.php';
try {
    (new \UnraidIcons\Icons())->publish();
} catch (Throwable $error) {
    fwrite(STDERR, 'unraid-icons: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
