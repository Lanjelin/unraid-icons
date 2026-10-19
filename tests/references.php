<?php
declare(strict_types=1);
require dirname(__DIR__) . '/source/include/Icons.php';
$root = sys_get_temp_dir() . '/unraid-icons-references-' . bin2hex(random_bytes(8));
mkdir($root, 0700, true);
$paths = ['icons' => "$root/icons", 'runtime' => "$root/runtime", 'vmImages' => "$root/vm", 'vmCustomImages' => "$root/vm-custom", 'cache' => "$root/cache", 'metadata' => "$root/metadata.json", 'dockerTemplates' => ["$root/templates"], 'vmTemplates' => "$root/savedtemplates.json", 'virsh' => "$root/virsh", 'docker' => "$root/docker"];
$uuid = '11111111-1111-4111-8111-111111111111';
$id = str_repeat('a', 64);
function verify(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function refused(callable $operation): void {
    try { $operation(); } catch (RuntimeException $error) {
        verify($error->getCode() === 409, 'Deletion failed for a reason other than reference safety'); return;
    }
    throw new RuntimeException('Referenced or unknown-usage icon was deleted');
}
function fixture(string $name, array $commands): void {
    global $root;
    file_put_contents("$root/$name.json", json_encode($commands, JSON_THROW_ON_ERROR));
}
try {
    mkdir($paths['icons']); mkdir("$root/templates");
    mkdir($paths['vmImages']);
    symlink('/unrelated/icon.png', $paths['vmImages'] . '/foreign.png');
    foreach (['virsh', 'docker'] as $service) {
        file_put_contents($paths[$service], "#!/bin/sh\nexec " . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/fixtures/reference-service.php') . ' ' . escapeshellarg("$root/$service.json") . ' "$@"' . "\n");
        chmod($paths[$service], 0700);
    }
    $image = imagecreatetruecolor(2, 2);
    ob_start(); imagepng($image); $png = ob_get_clean(); imagedestroy($image);
    $filename = 'jellyfin.png';
    file_put_contents($paths['icons'] . '/' . $filename, $png);
    $xml = '<domain><name>Stopped VM</name><metadata><vmtemplate xmlns="urn:unraid" icon="' . $filename . '"/></metadata></domain>';
    $vmCommands = ["--connect qemu:///system list --all --uuid" => "$uuid\n", "--connect qemu:///system dumpxml $uuid" => $xml, "--connect qemu:///system dumpxml $uuid --inactive" => $xml];
    fixture('virsh', $vmCommands);
    fixture('docker', ['ps -aq --no-trunc' => "$id\n", "inspect --type container $id" => json_encode([['Name' => '/Stopped container', 'Config' => ['Labels' => ['net.unraid.docker.icon' => 'file://' . $paths['icons'] . '/' . $filename]]]])]);
    file_put_contents("$root/templates/my-example.xml", '<Container><Name>Saved container</Name><Icon>file://' . $paths['icons'] . '/' . $filename . '</Icon></Container>');
    file_put_contents($paths['vmTemplates'], json_encode(['Saved VM' => ['overrides' => ['template' => ['icon' => $filename]]]]));
    $icons = new \UnraidIcons\Icons($paths);
    $record = $icons->library()[0];
    verify($record['usageKnown'] === true, 'Complete CLI/XML fixtures were not accepted');
    foreach (['VM: Stopped VM', 'VM: Stopped VM (saved definition)', 'Docker container: Stopped container', 'Docker template: Saved container', 'VM template: Saved VM'] as $reference) {
        verify(in_array($reference, $record['references'], true), 'Missed reference: ' . $reference);
    }
    refused(fn() => $icons->delete($filename));
    // Remove every reference except the inactive definition: that alone must protect it.
    fixture('docker', ['ps -aq --no-trunc' => '']);
    unlink("$root/templates/my-example.xml"); unlink($paths['vmTemplates']);
    $vmCommands["--connect qemu:///system dumpxml $uuid"] = '<domain><name>Running configuration</name></domain>';
    fixture('virsh', $vmCommands);
    refused(fn() => $icons->delete($filename));
    // Malformed XML cannot turn an unknown state into an unused icon.
    $vmCommands["--connect qemu:///system dumpxml $uuid --inactive"] = '<domain>';
    fixture('virsh', $vmCommands);
    verify($icons->library()[0]['usageKnown'] === false, 'Malformed XML did not poison usage scan');
    refused(fn() => $icons->delete($filename));
    // Core state can change after the library response; deletion must rescan.
    fixture('virsh', ['--connect qemu:///system list --all --uuid' => '']);
    $unused = $icons->library()[0];
    verify($unused['usageKnown'] && $unused['references'] === [], 'Confirmed unused state not recognized');
    file_put_contents("$root/templates/my-late.xml", '<Container><Name>Late reference</Name><Icon>' . $filename . '</Icon></Container>');
    refused(fn() => $icons->delete($filename));
    unlink("$root/templates/my-late.xml");
    $icons->delete($filename);
    foreach (['icons', 'runtime', 'vmImages'] as $location) {
        verify(!file_exists($paths[$location] . '/' . $filename) && !is_link($paths[$location] . '/' . $filename), 'Deletion left an icon or runtime link behind');
    }
    verify(readlink($paths['vmImages'] . '/foreign.png') === '/unrelated/icon.png', 'Publishing changed an unrelated native-picker link');
    // Existing installations retain hashed filenames because saved references use them.
    $legacy = 'unraid-icons-' . hash('sha256', $png) . '.png';
    file_put_contents($paths['icons'] . '/' . $legacy, $png);
    file_put_contents($paths['vmTemplates'], json_encode(['Existing VM' => ['icon' => $legacy]]));
    $existing = $icons->library()[0];
    verify($existing['filename'] === $legacy && in_array('VM template: Existing VM', $existing['references'], true), 'An existing icon reference was lost');
    refused(fn() => $icons->delete($legacy));
    unlink($paths['vmTemplates']);
    $icons->delete($legacy);
    // If external state changes after import, publishing must still never replace a native icon.
    file_put_contents($paths['icons'] . '/ubuntu.png', $png);
    file_put_contents($paths['vmImages'] . '/ubuntu.png', 'native icon sentinel');
    try {
        $icons->publish();
        throw new RuntimeException('Publishing accepted a conflicting native icon');
    } catch (RuntimeException $error) {
        verify($error->getCode() === 500, 'Unexpected publishing failure');
    }
    verify(file_get_contents($paths['vmImages'] . '/ubuntu.png') === 'native icon sentinel', 'Publishing overwrote a native icon');
    echo "Passed stopped VM/container, saved template, malformed XML, deletion rescan, and unused deletion scenarios.\n";
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) {
        if ($entry->isDir() && !$entry->isLink()) rmdir($entry->getPathname()); else unlink($entry->getPathname());
    }
    rmdir($root);
}
