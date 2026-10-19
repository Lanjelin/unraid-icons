<?php
declare(strict_types=1);
require dirname(__DIR__).'/source/include/Icons.php';

$root = sys_get_temp_dir().'/unraid-icons-iconify-'.bin2hex(random_bytes(8));
mkdir($root.'/cache', 0700, true);
$paths = [
    'icons' => "$root/icons", 'runtime' => "$root/runtime", 'vmImages' => "$root/vm", 'vmCustomImages' => "$root/vm-custom",
    'cache' => "$root/cache", 'metadata' => "$root/metadata.json",
    'dockerTemplates' => ["$root/templates"], 'vmTemplates' => "$root/savedtemplates.json",
    'virsh' => '/bin/false', 'docker' => '/bin/false',
];
$icons = new \UnraidIcons\Icons($paths);
$checks = 0;
function expect(bool $condition, string $message): void {
    global $checks;
    if (!$condition) { throw new RuntimeException($message); }
    $checks++;
}
function rejected(callable $operation, string $message, int $status = 400): void {
    try { $operation(); } catch (RuntimeException $error) {
        expect($error->getCode() === $status, $message.' returned unexpected status: '.$error->getCode());
        return;
    }
    throw new RuntimeException($message.' was accepted');
}
function png(int $width = 512, int $height = 512, int $red = 255): string {
    $image = imagecreatetruecolor($width, $height);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
    imagesetpixel($image, 20, 20, imagecolorallocatealpha($image, $red, 140, 47, 0));
    ob_start();
    try { imagepng($image); return ob_get_contents(); }
    finally { ob_end_clean(); imagedestroy($image); }
}
try {
    foreach (['tabler', 'mdi', 'ph'] as $collection) {
        file_put_contents("$root/cache/catalog-iconify-$collection.json", json_encode([
            'prefix' => $collection,
            'uncategorized' => ['error-404', 'hidden-icon', 'alias-icon', 'invalid/name', 'error-404', '123', 'number-123'],
            'categories' => ['General' => ['category-only', 'error-404', 'hidden-icon', 'alias-icon'], 'Malformed' => 'not-an-array'],
            'hidden' => ['hidden-icon'],
            'aliases' => ['alias-icon' => 'error-404', '123' => 'number-123'],
            'info' => ['author' => ['name' => 'Untrusted', 'url' => 'javascript:alert(1)']],
        ], JSON_THROW_ON_ERROR));
    }
    file_put_contents("$root/cache/catalog-selfhst.json", json_encode([['Jellyfin', 'jellyfin']]));
    file_put_contents("$root/cache/catalog-dashboard.json", json_encode(['truncated' => false, 'tree' => [['type' => 'blob', 'path' => 'plex.png']]]));
    foreach (['tabler', 'mdi', 'ph'] as $collection) {
        $catalog = $icons->catalog('iconify', $collection);
        expect(array_column($catalog, 'slug') === ['error-404', 'number-123', 'category-only'], 'Visible collection members were not deduplicated or filtered');
        expect(!in_array('123', array_column($catalog, 'slug'), true), 'Numeric alias was exposed as a visible icon');
        expect(in_array('number-123', array_column($catalog, 'slug'), true), 'Numeric alias incorrectly excluded its visible parent');
        expect($catalog[0]['iconId'] === "$collection:error-404", 'Collection caches leaked into each other');
        expect($catalog[0]['url'] === "https://api.iconify.design/$collection/error-404.svg", 'Unsafe Iconify catalog URL');
        expect($icons->iconifyAttribution($collection)['author']['name'] !== 'Untrusted', 'Remote metadata replaced authoritative attribution');
    }
    $parseCollection = new ReflectionMethod($icons, 'iconifyRows');
    $parseCollection->setAccessible(true);
    foreach ([
        ['hidden' => 'not-a-list'],
        ['hidden' => [true]],
        ['aliases' => 'not-a-dictionary'],
        ['aliases' => ['alias-icon' => ['invalid-parent']]],
    ] as $malformed) {
        expect($parseCollection->invoke($icons, ['prefix' => 'tabler', 'uncategorized' => ['error-404']] + $malformed, 'tabler') === [], 'Malformed exclusion data did not fail closed');
    }
    rejected(fn() => $icons->catalog('iconify', 'logos'), 'Uncurated collection');
    rejected(fn() => $icons->catalog('iconify', '../tabler'), 'Collection traversal');
    rejected(fn() => $icons->catalog('unknown'), 'Unknown provider');
    $png = png();
    foreach (['', 'not!base64', 'data:image/png;base64,'.base64_encode($png), base64_encode('<svg xmlns="http://www.w3.org/2000/svg"/>'), base64_encode(substr($png, 0, -12))] as $encoded) {
        rejected(fn() => $icons->importIconifyBase64($encoded, 'tabler:error-404', '#ff8c2f'), 'Invalid encoded PNG');
    }
    rejected(fn() => $icons->importIconifyBase64(str_repeat('A', 11184813), 'tabler:error-404', '#ff8c2f'), 'Oversized encoded PNG', 413);
    foreach (['logos:example', 'tabler:../error-404', 'tabler:error-404.svg', 'tabler:error-404?color=red', 'tabler:Error-404', 'tabler:'] as $id) {
        rejected(fn() => $icons->importIconifyPng($png, $id, '#ff8c2f'), 'Invalid identity '.$id);
    }
    foreach (['red', '#fff', '#ff8c2f00', 'ff8c2f', '#ff8c2f&url=x'] as $color) {
        rejected(fn() => $icons->importIconifyPng($png, 'tabler:error-404', $color), 'Invalid color '.$color);
    }
    foreach (['', '<svg xmlns="http://www.w3.org/2000/svg"/>', 'data:image/png;base64,'.base64_encode($png), "\xff\xd8\xffJPEG"] as $bytes) {
        rejected(fn() => $icons->importIconifyPng($bytes, 'tabler:error-404', '#ff8c2f'), 'Non-PNG upload');
    }
    rejected(fn() => $icons->importIconifyPng(png(511), 'tabler:error-404', '#ff8c2f'), 'Incorrect dimensions');
    rejected(fn() => $icons->importIconifyPng(substr($png, 0, -12), 'tabler:error-404', '#ff8c2f'), 'Missing PNG end');
    rejected(fn() => $icons->importIconifyPng(substr($png, 0, -20), 'tabler:error-404', '#ff8c2f'), 'Truncated PNG data');
    rejected(fn() => $icons->importIconifyPng($png.'extra', 'tabler:error-404', '#ff8c2f'), 'PNG trailing payload');
    $corrupt = $png;
    $corrupt[45] = chr(ord($corrupt[45]) ^ 1);
    rejected(fn() => $icons->importIconifyPng($corrupt, 'tabler:error-404', '#ff8c2f'), 'PNG checksum corruption');
    rejected(fn() => $icons->importIconifyPng(str_repeat('x', 8388609), 'tabler:error-404', '#ff8c2f'), 'Oversized PNG', 413);
    foreach (['not-in-catalog', 'alias-icon', 'hidden-icon'] as $slug) {
        rejected(fn() => $icons->importIconifyPng($png, 'tabler:'.$slug, '#ff8c2f'), 'Non-visible collection member');
    }
    $record = $icons->importIconifyBase64(base64_encode($png), 'tabler:error-404', '#FF8C2F');
    expect($record['provider'] === 'iconify' && $record['iconId'] === 'tabler:error-404' && $record['collection'] === 'tabler', 'Iconify identity was not persisted');
    expect($record['color'] === '#ff8c2f', 'Color was not normalized');
    expect($record['source'] === 'https://api.iconify.design/tabler/error-404.svg?color=%23ff8c2f', 'Stored source is not authoritative');
    expect($record['attribution'] === $icons->iconifyAttribution('tabler'), 'Attribution is missing from import response');
    expect($icons->library()[0]['attribution'] === $record['attribution'], 'Library discarded attribution');
    $saved = file_get_contents($paths['icons'].'/'.$record['filename']);
    $decoded = imagecreatefromstring($saved);
    expect(imagesx($decoded) === 512 && imagesy($decoded) === 512, 'Stored PNG has wrong dimensions');
    expect((imagecolorat($decoded, 0, 0) >> 24 & 127) === 127, 'Transparent pixels were lost');
    imagedestroy($decoded);
    expect(is_link($paths['runtime'].'/'.$record['filename']) && is_link($paths['vmImages'].'/'.$record['filename']), 'Icon was not published');
    $repeat = $icons->importIconifyPng($png, 'tabler:error-404', '#ff8c2f');
    expect($repeat['filename'] === $record['filename'], 'Identical PNG was not deduplicated');
    $different = $icons->importIconifyPng(png(512, 512, 100), 'tabler:error-404', '#648c2f');
    expect($different['filename'] !== $record['filename'] && file_get_contents($paths['icons'].'/'.$record['filename']) === $saved, 'Different PNG overwrote an existing icon');
    // Exercise the shared persistence boundary without making a network request.
    $persist = new ReflectionMethod($icons, 'persistPng');
    $persist->setAccessible(true);
    $custom = $persist->invoke($icons, $saved, 'https://example.com/icon.png', 'Error 404');
    expect($custom['filename'] === $record['filename'] && $custom['attribution'] === $record['attribution'], 'Deduplicated Custom URL import stripped attribution');
    expect($custom['source'] === $record['source'], 'Deduplicated Custom URL import relabeled Iconify provenance');
    $metadata = json_decode(file_get_contents($paths['metadata']), true, 512, JSON_THROW_ON_ERROR);
    expect($metadata[$record['filename']]['attribution'] === $record['attribution'], 'Attribution was not committed to persistent metadata');
    expect($icons->library()[0]['usageKnown'] === false, 'Unavailable services must not bypass deletion safety');
    echo "Passed $checks Iconify catalog, media validation, metadata, transparency, deduplication, and publication checks.\n";
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) {
        if ($entry->isDir() && !$entry->isLink()) { rmdir($entry->getPathname()); }
        else { unlink($entry->getPathname()); }
    }
    rmdir($root);
}
