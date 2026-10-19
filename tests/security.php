<?php
declare(strict_types=1);
require dirname(__DIR__) . '/source/include/Icons.php';

$root = sys_get_temp_dir() . '/unraid-icons-security-' . bin2hex(random_bytes(8));
mkdir($root, 0700, true);
$paths = [
    'icons' => "$root/icons", 'runtime' => "$root/runtime", 'vmImages' => "$root/vm", 'vmCustomImages' => "$root/vm-custom",
    'cache' => "$root/cache", 'metadata' => "$root/metadata.json",
    'dockerTemplates' => ["$root/templates"], 'vmTemplates' => "$root/savedtemplates.json",
    'virsh' => '/bin/false', 'docker' => '/bin/false',
];
$icons = new \UnraidIcons\Icons($paths);
$checks = 0;
function expectFailure(callable $operation, string $context, int $status = 400): void {
    global $checks;
    try { $operation(); } catch (RuntimeException $error) {
        if ($error->getCode() !== $status) throw new RuntimeException('Unexpected failure for ' . $context, 0, $error);
        $checks++; return;
    }
    throw new RuntimeException('Unsafe operation accepted: ' . $context);
}
try {
    // These must be rejected before any outbound request, including mapped IPv6
    // and alternate numeric loopback spellings understood by some URL clients.
    foreach ([
        'http://127.0.0.1/icon.png', 'http://127.1/icon.png',
        'http://2130706433/icon.png', 'http://0x7f000001/icon.png',
        'http://10.0.0.1/icon.png', 'http://172.16.0.1/icon.png',
        'http://192.168.1.1/icon.png', 'http://169.254.169.254/latest/meta-data/',
        'http://100.64.0.1/icon.png', 'http://0.0.0.0/icon.png',
        'http://[::1]/icon.png', 'http://[::ffff:127.0.0.1]/icon.png',
        'http://[fc00::1]/icon.png', 'http://[fe80::1]/icon.png',
        'file:///etc/passwd', 'gopher://127.0.0.1/', 'data:image/png;base64,AAAA',
        'https://user:password@example.com/icon.png',
    ] as $url) {
        expectFailure(fn() => $icons->download($url), $url);
    }
    foreach (['../outside.png', '/etc/passwd', 'unraid-icons-../../outside.png', 'icons_../outside.png'] as $filename) {
        expectFailure(fn() => $icons->delete($filename), 'path traversal ' . $filename);
    }
    // An unavailable daemon is not evidence that an icon is unused.
    if (!is_dir($paths['icons'])) mkdir($paths['icons'], 0700, true);
    $image = imagecreatetruecolor(2, 2);
    ob_start(); imagepng($image); $png = ob_get_clean(); imagedestroy($image);
    $filename = 'unraid-icons-' . hash('sha256', $png) . '.png';
    file_put_contents($paths['icons'] . '/' . $filename, $png);
    $icons->publish();
    $records = $icons->library();
    $record = array_values(array_filter($records, fn($item) => $item['filename'] === $filename))[0] ?? null;
    if (!$record || $record['usageKnown'] !== false) throw new RuntimeException('Unavailable services must make usage unknown');
    expectFailure(fn() => $icons->delete($filename), 'deleting with unknown usage', 409);
    if (!is_file($paths['icons'] . '/' . $filename)) throw new RuntimeException('Refused deletion removed the icon');
    if (!is_link($paths['vmImages'] . '/' . $filename)) throw new RuntimeException('VM icon not published');
    $checks += 3;
    echo "Passed $checks security and persistence checks.\n";
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) {
        if ($entry->isDir() && !$entry->isLink()) rmdir($entry->getPathname());
        else unlink($entry->getPathname());
    }
    rmdir($root);
}
