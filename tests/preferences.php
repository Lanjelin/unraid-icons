<?php
declare(strict_types=1);
require dirname(__DIR__).'/source/include/Icons.php';
$root = sys_get_temp_dir().'/unraid-icons-preferences-'.bin2hex(random_bytes(8));
mkdir($root, 0700, true);
$paths = ['preferences' => $root.'/preferences.json', 'cache' => $root.'/cache'];
function check(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
try {
    $icons = new \UnraidIcons\Icons($paths);
    check($icons->preferences()['headerShortcut'] === false, 'New installations must not show a header shortcut');
    $icons->savePreferences(true);
    check((new \UnraidIcons\Icons($paths))->preferences()['headerShortcut'] === true, 'Enabled shortcut was not persisted');
    $icons->savePreferences(false);
    check((new \UnraidIcons\Icons($paths))->preferences()['headerShortcut'] === false, 'Disabled shortcut was not persisted');
    file_put_contents($paths['preferences'], '{"headerShortcut":"false"}');
    try {
        $icons->preferences();
        throw new LogicException('A string value was interpreted as an enabled shortcut');
    } catch (RuntimeException $error) {
        check($error->getCode() === 500, 'Unexpected invalid-preferences response');
    }
    echo "Passed shortcut default, enable/disable persistence, and invalid stored value checks.\n";
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) {
        if ($entry->isDir() && !$entry->isLink()) rmdir($entry->getPathname()); else unlink($entry->getPathname());
    }
    rmdir($root);
}
