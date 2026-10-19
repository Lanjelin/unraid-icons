<?php
declare(strict_types=1);
require dirname(__DIR__).'/source/include/Icons.php';
$root = sys_get_temp_dir().'/unraid-icons-catalogs-'.bin2hex(random_bytes(8));
mkdir($root, 0700, true);
function expect(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
try {
    file_put_contents($root.'/catalog-selfhst.json', json_encode([['Jellyfin', 'jellyfin']]));
    file_put_contents($root.'/catalog-dashboard.json', json_encode([
        'truncated' => false,
        'tree' => [
            ['type'=>'blob', 'path'=>'plex.png'],
            ['type'=>'blob', 'path'=>'plex-light.png'],
            ['type'=>'blob', 'path'=>'../escape.png'],
            ['type'=>'blob', 'path'=>'https://example.com/evil.png'],
            ['type'=>'blob', 'path'=>'unsupported.svg'],
            ['type'=>'tree', 'path'=>'directory.png'],
            ['not-a-filename'],
        ],
    ]));
    $icons = new \UnraidIcons\Icons(['cache'=>$root]);
    $dashboard = $icons->catalog('dashboard');
    expect(array_column($dashboard, 'slug') === ['plex', 'plex-light'], 'PNG index filtering or variant handling failed');
    expect($dashboard[0]['url'] === 'https://raw.githubusercontent.com/homarr-labs/dashboard-icons/main/png/plex.png', 'Dashboard download URL points outside the PNG provider');
    expect($dashboard[1]['url'] === 'https://raw.githubusercontent.com/homarr-labs/dashboard-icons/main/png/plex-light.png', 'Variant was mapped to the base icon');
    $selfhst = $icons->catalog('selfhst');
    expect(array_column($selfhst, 'slug') === ['jellyfin'], 'Provider caches leaked into each other');
    expect($selfhst[0]['url'] === 'https://raw.githubusercontent.com/selfhst/icons/main/png/jellyfin.png', 'Existing selfh.st downloads changed');
    try {
        $icons->catalog('../other');
        throw new LogicException('An unknown provider was accepted');
    } catch (RuntimeException $error) {
        expect($error->getCode() === 400, 'Unexpected invalid provider error');
    }
    echo "Passed provider isolation, PNG-only catalog, variant, unsafe filename, and unknown-provider checks.\n";
} finally {
    foreach (new DirectoryIterator($root) as $entry) {
        if (!$entry->isDot()) unlink($entry->getPathname());
    }
    rmdir($root);
}
