<?php
namespace UnraidIcons;

/** Persistent icons; all mutations share the RAM lock. */
final class Icons
{
    private const FILE_PATTERN = '/\A[a-z0-9][a-z0-9_-]{0,99}\.png\z/D';
    private const CATALOGS = [
        'selfhst' => ['name' => 'selfh.st', 'url' => 'https://raw.githubusercontent.com/selfhst/icons/main/index-consolidated.json'],
        'dashboard' => ['name' => 'Dashboard Icons', 'url' => 'https://api.github.com/repos/homarr-labs/dashboard-icons/git/trees/main'],
    ];
    private const ICONIFY_COLLECTIONS = [
        'tabler' => [
            'collection' => 'Tabler Icons',
            'author' => ['name' => 'Paweł Kuna', 'url' => 'https://github.com/tabler/tabler-icons'],
            'license' => ['title' => 'MIT License', 'spdx' => 'MIT', 'url' => 'https://github.com/tabler/tabler-icons/blob/main/LICENSE'],
        ],
        'mdi' => [
            'collection' => 'Material Design Icons',
            'author' => ['name' => 'Pictogrammers', 'url' => 'https://pictogrammers.com/'],
            'license' => ['title' => 'Apache License 2.0', 'spdx' => 'Apache-2.0', 'url' => 'https://github.com/Templarian/MaterialDesign/blob/master/LICENSE'],
        ],
        'ph' => [
            'collection' => 'Phosphor',
            'author' => ['name' => 'Phosphor Icons', 'url' => 'https://phosphoricons.com/'],
            'license' => ['title' => 'MIT License', 'spdx' => 'MIT', 'url' => 'https://github.com/phosphor-icons/core/blob/main/LICENSE'],
        ],
    ];
    private const MAX_BYTES = 8388608;
    private const MAX_PIXELS = 4194304;
    private $paths;
    private $scanDeadline = 0;

    /** Paths are overridable for isolated administration/smoke tests, not user settings. */
    public function __construct(array $paths = [])
    {
        $this->paths = array_replace([
            'icons' => '/boot/config/plugins/unraid-icons/icons',
            'runtime' => '/usr/local/emhttp/plugins/unraid-icons/icons',
            'vmImages' => '/usr/local/emhttp/plugins/dynamix.vm.manager/templates/images',
            'vmCustomImages' => '/usr/local/emhttp/boot/config/plugins/dynamix.vm.manager/templates/images',
            'cache' => '/var/tmp/unraid-icons',
            'metadata' => '/boot/config/plugins/unraid-icons/metadata.json',
            'preferences' => '/boot/config/plugins/unraid-icons/preferences.json',
            'dockerTemplates' => ['/boot/config/plugins/dockerMan/templates-user', '/boot/config/plugins/dockerMan/templates'],
            'vmTemplates' => '/boot/config/plugins/dynamix.vm.manager/savedtemplates.json',
            'virsh' => '/usr/bin/virsh',
            'docker' => '/usr/bin/docker',
        ], $paths);
    }

    public function preferences(): array
    {
        $path = $this->paths['preferences'];
        if (!file_exists($path) && !is_link($path)) { return ['headerShortcut' => false]; }
        if (is_link($path)) { throw new \RuntimeException('Unsafe plugin preferences path.', 500); }
        $preferences = json_decode($this->readBounded($path), true);
        if (!is_array($preferences) || !isset($preferences['headerShortcut']) || !is_bool($preferences['headerShortcut'])) {
            throw new \RuntimeException('Plugin preferences are invalid. Restore preferences.json before saving settings.', 500);
        }
        return ['headerShortcut' => $preferences['headerShortcut']];
    }

    public function savePreferences(bool $headerShortcut): array
    {
        return $this->locked(function () use ($headerShortcut) {
            $preferences = ['headerShortcut' => $headerShortcut];
            $this->atomicWrite($this->paths['preferences'], $this->json($preferences));
            return $preferences;
        });
    }

    public function library(): array
    {
        return $this->locked(function () {
            $this->publishUnlocked();
            return $this->records($this->usage());
        });
    }

    public function publish(): void
    {
        $this->locked(function () { $this->publishUnlocked(); });
    }

    public function catalog(string $provider = 'selfhst', string $collection = 'tabler'): array
    {
        if ($provider === 'iconify') { return $this->iconifyCatalog($collection); }
        if (!isset(self::CATALOGS[$provider])) { throw new \RuntimeException('Unknown icon provider.', 400); }
        return $this->locked(function () use ($provider) {
            $path = $this->paths['cache'].'/catalog-'.$provider.'.json';
            if (is_file($path) && !is_link($path) && filemtime($path) > time() - 3600) {
                $cached = json_decode($this->readBounded($path), true);
                if (is_array($cached)) {
                    $icons = $this->catalogRows($cached, $provider);
                    if ($icons) { return $icons; }
                }
            }
            $data = $this->fetch(self::CATALOGS[$provider]['url']);
            $rows = json_decode($data, true);
            if ($provider === 'dashboard') {
                $pngTree = null;
                if (is_array($rows) && ($rows['truncated'] ?? true) === false && is_array($rows['tree'] ?? null)) {
                    foreach ($rows['tree'] as $entry) {
                        if (($entry['path'] ?? '') === 'png' && ($entry['type'] ?? '') === 'tree' &&
                            is_string($entry['sha'] ?? null) && preg_match('/\A[a-f0-9]{40}(?:[a-f0-9]{24})?\z/D', $entry['sha'])) {
                            $pngTree = $entry['sha'];
                            break;
                        }
                    }
                }
                if ($pngTree === null) { throw new \RuntimeException('Dashboard Icons returned an incomplete repository index.', 502); }
                $data = $this->fetch('https://api.github.com/repos/homarr-labs/dashboard-icons/git/trees/'.$pngTree);
                $rows = json_decode($data, true);
            }
            $icons = is_array($rows) ? $this->catalogRows($rows, $provider) : [];
            if (!$icons) {
                throw new \RuntimeException('The '.self::CATALOGS[$provider]['name'].' catalog contained no supported icons or returned invalid data.', 502);
            }
            $this->atomicWrite($path, $data);
            return $icons;
        });
    }

    /** Attribution is pinned to the curated registry, never supplied by the browser. */
    public function iconifyAttribution(string $collection = 'tabler'): array
    {
        if (!isset(self::ICONIFY_COLLECTIONS[$collection])) {
            throw new \RuntimeException('Unknown Iconify collection. Choose Tabler Icons, Material Design Icons, or Phosphor.', 400);
        }
        return self::ICONIFY_COLLECTIONS[$collection];
    }

    private function iconifyCatalog(string $collection): array
    {
        $this->iconifyAttribution($collection);
        return $this->locked(function () use ($collection) {
            $path = $this->paths['cache'].'/catalog-iconify-'.$collection.'.json';
            if (is_file($path) && !is_link($path) && filemtime($path) > time() - 3600) {
                $rows = json_decode($this->readBounded($path), true);
                $icons = is_array($rows) ? $this->iconifyRows($rows, $collection) : [];
                if ($icons) { return $icons; }
            }
            $data = $this->fetch('https://api.iconify.design/collection?prefix='.$collection.'&info=true');
            $rows = json_decode($data, true);
            $icons = is_array($rows) ? $this->iconifyRows($rows, $collection) : [];
            if (!$icons) {
                throw new \RuntimeException('Iconify returned an invalid or empty collection. Try downloading the catalog again later.', 502);
            }
            $this->atomicWrite($path, $data);
            return $icons;
        });
    }

    private function iconifyRows(array $rows, string $collection): array
    {
        if (($rows['prefix'] ?? null) !== $collection) { return []; }
        $groups = [$rows['uncategorized'] ?? []];
        if (is_array($rows['categories'] ?? null)) {
            foreach ($rows['categories'] as $group) { $groups[] = $group; }
        }
        $excluded = [];
        $hidden = $rows['hidden'] ?? [];
        $aliases = $rows['aliases'] ?? [];
        if (!is_array($hidden) || !is_array($aliases)) { return []; }
        foreach ($hidden as $name) {
            if (!is_string($name)) { return []; }
            $excluded[$name] = true;
        }
        // aliases is a name => parent dictionary. Numeric JSON object keys
        // become PHP integer keys, but still identify aliases, not parents.
        foreach ($aliases as $name => $parent) {
            if (!is_string($parent)) { return []; }
            $excluded[(string) $name] = true;
        }
        $icons = [];
        foreach ($groups as $group) {
            if (!is_array($group)) { continue; }
            foreach ($group as $slug) {
                if (!is_string($slug) || !preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $slug) ||
                    strlen($slug) > 160 || isset($excluded[$slug]) || isset($icons[$slug])) { continue; }
                $url = 'https://api.iconify.design/'.$collection.'/'.$slug.'.svg';
                $icons[$slug] = [
                    'name' => ucwords(str_replace('-', ' ', $slug)), 'slug' => $slug,
                    'url' => $url, 'preview' => $url, 'iconId' => $collection.':'.$slug,
                ];
            }
        }
        return array_values($icons);
    }

    public function importIconifyBase64(string $encoded, string $iconId, string $color): array
    {
        if (strlen($encoded) > 11184812) {
            throw new \RuntimeException('The encoded Iconify PNG exceeds the 8 MiB image limit.', 413);
        }
        $bytes = base64_decode($encoded, true);
        if ($encoded === '' || $bytes === false) {
            throw new \RuntimeException('The browser sent invalid PNG data. Try Download again.', 400);
        }
        return $this->importIconifyPng($bytes, $iconId, $color);
    }

    public function importIconifyPng(string $bytes, string $iconId, string $color): array
    {
        if (!preg_match('/\A(tabler|mdi|ph):([a-z0-9]+(?:-[a-z0-9]+)*)\z/D', $iconId, $match) ||
            strlen($match[2] ?? '') > 160) {
            throw new \RuntimeException('Invalid Iconify icon ID. Select an icon from a supported collection.', 400);
        }
        if (!preg_match('/\A#[a-fA-F0-9]{6}\z/D', $color)) {
            throw new \RuntimeException('Invalid Iconify color. Use a six-digit hexadecimal color such as #ff8c2f.', 400);
        }
        $this->validateIconifyPng($bytes);
        $png = $this->normalize($bytes);
        [$collection, $slug] = [$match[1], $match[2]];
        $found = false;
        foreach ($this->iconifyCatalog($collection) as $icon) {
            if ($icon['slug'] === $slug) { $found = true; break; }
        }
        if (!$found) { throw new \RuntimeException('This icon is not a visible member of the selected collection. Refresh the catalog and select it again.', 400); }
        $color = strtolower($color);
        $url = 'https://api.iconify.design/'.$collection.'/'.$slug.'.svg?color='.rawurlencode($color);
        return $this->persistPng($png, $url, ucwords(str_replace('-', ' ', $slug)), [
            'provider' => 'iconify', 'collection' => $collection, 'iconId' => $iconId,
            'color' => $color, 'attribution' => $this->iconifyAttribution($collection),
        ]);
    }

    private function validateIconifyPng(string $bytes): void
    {
        $length = strlen($bytes);
        if ($length > self::MAX_BYTES) { throw new \RuntimeException('The Iconify PNG exceeds the 8 MiB upload limit.', 413); }
        if ($length < 33 || substr($bytes, 0, 8) !== "\x89PNG\r\n\x1a\n") {
            throw new \RuntimeException('Upload a PNG generated by the Iconify Download button, not SVG or another image format.', 400);
        }
        $offset = 8;
        $hasData = false;
        while ($offset + 12 <= $length) {
            $size = unpack('N', substr($bytes, $offset, 4))[1];
            $type = substr($bytes, $offset + 4, 4);
            if ($size > $length - $offset - 12 ||
                !preg_match('/\A[A-Za-z]{4}\z/D', $type) ||
                hash('crc32b', substr($bytes, $offset + 4, $size + 4), true) !== substr($bytes, $offset + 8 + $size, 4)) {
                throw new \RuntimeException('The uploaded PNG is damaged or incomplete. Download the icon again.', 400);
            }
            if ($offset === 8 && ($type !== 'IHDR' || $size !== 13)) {
                throw new \RuntimeException('The uploaded PNG has an invalid header.', 400);
            }
            if ($type === 'IHDR') {
                if ($offset !== 8 || $size !== 13) { throw new \RuntimeException('The uploaded PNG has an invalid header.', 400); }
                $dimensions = unpack('Nwidth/Nheight', substr($bytes, $offset + 8, 8));
                if ($dimensions['width'] !== 512 || $dimensions['height'] !== 512) {
                    throw new \RuntimeException('Iconify uploads must be exactly 512 × 512 pixels. Use the Download button to convert the icon.', 400);
                }
            }
            if ($type === 'IDAT') { $hasData = true; }
            $offset += $size + 12;
            if ($type === 'IEND') {
                if ($size === 0 && $hasData && $offset === $length) { return; }
                break;
            }
        }
        throw new \RuntimeException('The uploaded PNG is damaged or incomplete. Download the icon again.', 400);
    }

    public function download(string $url, string $name = ''): array
    {
        // Network and decoding run outside the CRUD lock; no library state changes yet.
        $png = $this->normalize($this->fetch($url));
        $name = trim($name);
        if ($name === '') {
            $name = pathinfo(rawurldecode((string) parse_url($url, PHP_URL_PATH)), PATHINFO_FILENAME);
        }
        $name = preg_replace('/[\x00-\x1f\x7f]/u', '', $name) ?? '';
        $name = substr($name, 0, 160);
        if (!preg_match('//u', $name)) {
            $name = 'Custom icon';
        }
        return $this->persistPng($png, $url, $name);
    }

    private function persistPng(string $png, string $url, string $name, array $details = []): array
    {
        return $this->locked(function () use ($png, $url, $name, $details) {
            $this->directory($this->paths['icons']);
            $filename = $this->filename($name, $png);
            $path = $this->paths['icons'].'/'.$filename;
            if (is_link($path)) {
                throw new \RuntimeException('The persistent icon path is unexpectedly a symbolic link.', 500);
            }
            if (!is_file($path)) {
                $this->atomicWrite($path, $png);
            }
            $metadata = $this->metadata();
            $entry = ['name' => $name ?: 'Custom icon', 'source' => $url] + $details;
            // Identical bytes may reuse an attributed file. A Custom URL import
            // must not strip its original license or relabel its provenance.
            if (!$details && ($metadata[$filename]['provider'] ?? null) === 'iconify') {
                $entry = $metadata[$filename] + $entry;
            }
            $metadata[$filename] = $entry;
            // Metadata is committed separately: a failed metadata write never replaces image bytes.
            $this->atomicWrite($this->paths['metadata'], $this->json($metadata));
            $this->publishUnlocked();
            $usage = $this->usage();
            return $this->record($filename, $metadata, $usage);
        });
    }

    private function filename(string $name, string $png): string
    {
        $slug = trim(substr(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), 0, 60), '-');
        $base = $slug ?: 'icon';
        $digest = hash('sha256', $png);
        for ($index = 1; $index <= 10000; $index++) {
            $filename = $base.($index === 1 ? '' : '_'.$index).'.png';
            $path = $this->paths['icons'].'/'.$filename;
            foreach ([$this->paths['runtime'], $this->paths['vmImages'], $this->paths['vmCustomImages']] as $directory) {
                $link = $directory.'/'.$filename;
                if ((file_exists($link) || is_link($link)) && (!is_link($link) || readlink($link) !== $path)) {
                    continue 2;
                }
            }
            if (is_file($path) && !is_link($path) && hash_file('sha256', $path) === $digest) {
                return $filename;
            }
            if (file_exists($path) || is_link($path)) { continue; }
            return $filename;
        }
        throw new \RuntimeException('Too many icons share this name. Choose a different name.', 409);
    }

    public function delete(string $filename): void
    {
        if (!preg_match(self::FILE_PATTERN, $filename)) {
            throw new \RuntimeException('Invalid icon filename.', 400);
        }
        $this->locked(function () use ($filename) {
            $path = $this->paths['icons'].'/'.$filename;
            if (!is_file($path) || is_link($path)) {
                throw new \RuntimeException('Icon not found.', 404);
            }
            // Never trust the browser's library snapshot: rescan while holding the CRUD lock.
            $usage = $this->usage();
            if (!$usage['known']) {
                throw new \RuntimeException('Cannot safely check all VM and Docker references. Start both services and try again.', 409);
            }
            if (!empty($usage['references'][$filename])) {
                throw new \RuntimeException('This icon is still referenced by a VM, Docker container, or saved template.', 409);
            }
            $metadata = $this->metadata();
            unset($metadata[$filename]);
            // If metadata cannot be saved, abort before unlinking the icon.
            $this->atomicWrite($this->paths['metadata'], $this->json($metadata));
            if (!unlink($path)) {
                throw new \RuntimeException('Could not delete the icon file.', 500);
            }
            $this->publishUnlocked();
        });
    }

    private function locked(callable $callback)
    {
        $this->directory($this->paths['cache']);
        $lockPath = $this->paths['cache'].'/library.lock';
        if (is_link($lockPath)) {
            throw new \RuntimeException('Unsafe icon lock path.', 500);
        }
        $lock = @fopen($lockPath, 'c');
        if ($lock === false) {
            throw new \RuntimeException('Cannot open the icon library lock.', 500);
        }
        try {
            $deadline = microtime(true) + 30;
            do {
                if (flock($lock, LOCK_EX | LOCK_NB)) {
                    try { return $callback(); } finally { flock($lock, LOCK_UN); }
                }
                usleep(50000);
            } while (microtime(true) < $deadline);
            throw new \RuntimeException('The icon library is busy. Please try again.', 503);
        } finally {
            fclose($lock);
        }
    }

    private function directory(string $path): void
    {
        if (is_link($path) || (!is_dir($path) && !@mkdir($path, 0755, true))) {
            throw new \RuntimeException('Cannot create a safe icon directory: '.$path, 500);
        }
    }

    private function atomicWrite(string $path, string $data): void
    {
        $this->directory(dirname($path));
        if (is_link($path)) {
            throw new \RuntimeException('Refusing to overwrite a symbolic link.', 500);
        }
        $temporary = tempnam(dirname($path), '.unraid-icons-');
        if ($temporary === false) {
            throw new \RuntimeException('Cannot create a temporary icon file.', 500);
        }
        try {
            if (file_put_contents($temporary, $data) !== strlen($data) || !chmod($temporary, 0644) || !rename($temporary, $path)) {
                throw new \RuntimeException('Cannot save the icon library. Check available flash space and permissions.', 500);
            }
        } finally {
            if (is_file($temporary)) { @unlink($temporary); }
        }
    }

    private function json(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) { throw new \RuntimeException('Cannot encode icon metadata.', 500); }
        return $json;
    }

    private function readBounded(string $path): string
    {
        $data = @file_get_contents($path, false, null, 0, self::MAX_BYTES + 1);
        if ($data === false || strlen($data) > self::MAX_BYTES) {
            throw new \RuntimeException('Cannot read a complete library configuration file.', 500);
        }
        return $data;
    }

    private function metadata(): array
    {
        $path = $this->paths['metadata'];
        if (!file_exists($path) && !is_link($path)) { return []; }
        if (is_link($path)) { throw new \RuntimeException('Unsafe icon metadata path.', 500); }
        $metadata = json_decode($this->readBounded($path), true);
        if (!is_array($metadata)) { throw new \RuntimeException('Icon metadata is invalid; restore or repair metadata.json before changing the library.', 500); }
        return $metadata;
    }

    private function filenames(): array
    {
        $this->directory($this->paths['icons']);
        $files = [];
        foreach (new \DirectoryIterator($this->paths['icons']) as $entry) {
            if (!$entry->isLink() && $entry->isFile() && preg_match(self::FILE_PATTERN, $entry->getFilename())) {
                $files[] = $entry->getFilename();
            }
        }
        sort($files, SORT_STRING);
        return $files;
    }

    private function publishUnlocked(): void
    {
        $files = array_fill_keys($this->filenames(), true);
        foreach ([$this->paths['runtime'], $this->paths['vmImages']] as $directory) {
            $this->directory($directory);
            foreach (new \DirectoryIterator($directory) as $entry) {
                $filename = $entry->getFilename();
                if (!preg_match(self::FILE_PATTERN, $filename)) { continue; }
                $destination = $directory.'/'.$filename;
                $target = $this->paths['icons'].'/'.$filename;
                if ($entry->isLink()) {
                    if (readlink($destination) !== $target) {
                        if (isset($files[$filename])) {
                            throw new \RuntimeException('A managed icon link has an unexpected target.', 500);
                        }
                        continue;
                    }
                    if (!isset($files[$filename]) && !unlink($destination)) {
                        throw new \RuntimeException('Cannot remove a stale icon link.', 500);
                    }
                } elseif (isset($files[$filename])) {
                    throw new \RuntimeException('An unmanaged file conflicts with an icon link.', 500);
                }
            }
            foreach ($files as $filename => $_) {
                $destination = $directory.'/'.$filename;
                if (!is_link($destination) && !@symlink($this->paths['icons'].'/'.$filename, $destination)) {
                    throw new \RuntimeException('Cannot publish an icon link.', 500);
                }
            }
        }
    }

    private function records(array $usage): array
    {
        $metadata = $this->metadata();
        $records = [];
        foreach ($this->filenames() as $filename) { $records[] = $this->record($filename, $metadata, $usage); }
        usort($records, function ($a, $b) { return strnatcasecmp($a['name'], $b['name']); });
        return $records;
    }

    private function record(string $filename, array $metadata, array $usage): array
    {
        $name = $metadata[$filename]['name'] ?? 'Custom icon';
        return [
            'filename' => $filename,
            'name' => is_string($name) ? $name : 'Custom icon',
            'url' => '/plugins/unraid-icons/icons/'.$filename,
            'vmIcon' => $filename,
            'dockerIcon' => 'file://'.$this->paths['icons'].'/'.$filename,
            'references' => array_values(array_unique($usage['references'][$filename] ?? [])),
            'usageKnown' => $usage['known'],
        ] + array_intersect_key($metadata[$filename] ?? [], array_flip(['source', 'provider', 'collection', 'iconId', 'color', 'attribution']));
    }

    private function catalogRows(array $rows, string $provider): array
    {
        $icons = [];
        if ($provider === 'dashboard' && ($rows['truncated'] ?? true) !== false) { return []; }
        $entries = $provider === 'dashboard' ? ($rows['tree'] ?? []) : $rows;
        if (!is_array($entries)) { return []; }
        foreach ($entries as $row) {
            if ($provider === 'dashboard') {
                if (!is_array($row) || ($row['type'] ?? '') !== 'blob' || !is_string($row['path'] ?? null) ||
                    !preg_match('/\A([a-zA-Z0-9][a-zA-Z0-9._-]{0,199})\.png\z/D', $row['path'], $match)) { continue; }
                $slug = $match[1];
                $name = ucwords(str_replace(['-', '_'], ' ', $slug));
                $url = 'https://raw.githubusercontent.com/homarr-labs/dashboard-icons/main/png/'.rawurlencode($row['path']);
            } else {
                if (!is_array($row) || !isset($row[0], $row[1]) || !is_string($row[0]) || !is_string($row[1]) ||
                    !preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]{0,199}\z/D', $row[1])) { continue; }
                $name = $row[0];
                $slug = $row[1];
                $url = 'https://raw.githubusercontent.com/selfhst/icons/main/png/'.rawurlencode($slug).'.png';
            }
            $icons[] = ['name' => $name, 'slug' => $slug, 'url' => $url, 'preview' => $url];
        }
        return $icons;
    }

    private function normalize(string $bytes): string
    {
        if (!extension_loaded('gd')) { throw new \RuntimeException('The PHP GD extension is required to import icons.', 503); }
        $info = @getimagesizefromstring($bytes);
        if ($info === false || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) {
            throw new \RuntimeException('Use a PNG, JPEG, WebP, or GIF image. SVG is not supported; choose a PNG version of the icon.', 400);
        }
        if ($info[0] < 1 || $info[1] < 1 || $info[0] > 4096 || $info[1] > 4096 || $info[0] * $info[1] > self::MAX_PIXELS) {
            throw new \RuntimeException('The image is too large. Use an icon with at most 4 megapixels and 4096 pixels per side.', 400);
        }
        $image = @imagecreatefromstring($bytes);
        if ($image === false) { throw new \RuntimeException('Cannot decode this image. Its format may not be enabled in PHP GD.', 400); }
        try {
            imagealphablending($image, false);
            imagesavealpha($image, true);
            ob_start();
            try {
                if (!imagepng($image, null, 9)) { throw new \RuntimeException('Cannot encode the icon as PNG.', 500); }
                $png = ob_get_contents();
            } finally { ob_end_clean(); }
            if (!is_string($png) || strlen($png) > self::MAX_BYTES) {
                throw new \RuntimeException('The normalized PNG exceeds the 8 MiB limit.', 400);
            }
            return $png;
        } finally { imagedestroy($image); }
    }

    /** Only globally routable addresses; transition/tunnel and special-use ranges fail closed. */
    private function publicAddress(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP) === false) { return false; }
        $packed = inet_pton($address);
        $blocks = strlen($packed) === 4 ? [
            '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
            '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16',
            '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/3',
        ] : ['2001::/23', '2001:db8::/32', '2002::/16', '3fff::/20'];
        if (strlen($packed) === 16 && !$this->inNetwork($packed, '2000::/3')) { return false; }
        foreach ($blocks as $block) { if ($this->inNetwork($packed, $block)) { return false; } }
        return true;
    }

    private function inNetwork(string $packed, string $network): bool
    {
        [$base, $bits] = explode('/', $network);
        $base = inet_pton($base);
        if (strlen($base) !== strlen($packed)) { return false; }
        $whole = intdiv((int) $bits, 8);
        $remaining = (int) $bits % 8;
        return substr($packed, 0, $whole) === substr($base, 0, $whole) &&
            (!$remaining || ((ord($packed[$whole]) ^ ord($base[$whole])) & (255 << (8 - $remaining))) === 0);
    }

    private function endpoint(string $url, float $deadline): array
    {
        if (strlen($url) > 4096 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            throw new \RuntimeException('Enter a valid public HTTP or HTTPS URL without whitespace.', 400);
        }
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host']) ||
            !in_array(strtolower($parts['scheme']), ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass'])) {
            throw new \RuntimeException('Only public HTTP and HTTPS image URLs without credentials are allowed.', 400);
        }
        $host = strtolower(trim($parts['host'], '[]'));
        if ($host === '' || strpos($host, '%') !== false) { throw new \RuntimeException('Invalid URL host.', 400); }
        $literal = filter_var($host, FILTER_VALIDATE_IP) !== false;
        if ($literal) {
            $addresses = [$host];
        } else {
            if (strlen($host) > 253 || strpos($host, '.') === false ||
                !preg_match('/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?\.?\z/Di', $host)) {
                throw new \RuntimeException('Use a public DNS hostname or IP address.', 400);
            }
            // libc DNS calls have no PHP timeout. Isolate them in a bounded CLI
            // child so DNS stalls cannot bypass the overall download deadline.
            $lookup = '$r = @dns_get_record($argv[1], DNS_A | DNS_AAAA);'
                .'if ($r === false) { exit(1); } echo json_encode($r);';
            try {
                $records = json_decode($this->command([PHP_BINDIR.'/php', '-n', '-r', $lookup, $host], $deadline), true);
            } catch (\Throwable $error) {
                throw new \RuntimeException('The URL hostname could not be resolved within the download time limit.', 502);
            }
            $addresses = [];
            if (is_array($records)) {
                foreach ($records as $record) {
                    if (isset($record['ip'])) { $addresses[] = $record['ip']; }
                    if (isset($record['ipv6'])) { $addresses[] = $record['ipv6']; }
                }
            }
        }
        if (!$addresses) { throw new \RuntimeException('The URL hostname could not be resolved.', 400); }
        foreach ($addresses as $address) {
            if (!$this->publicAddress($address)) {
                throw new \RuntimeException('Private, local, and special-use network addresses are not allowed.', 400);
            }
        }
        $port = $parts['port'] ?? (strtolower($parts['scheme']) === 'https' ? 443 : 80);
        if ($port < 1 || $port > 65535) { throw new \RuntimeException('Invalid URL port.', 400); }
        return [$host, $port, $addresses[0], $literal];
    }

    private function fetch(string $url): string
    {
        if (!extension_loaded('curl')) { throw new \RuntimeException('The PHP cURL extension is required to download icons.', 503); }
        $deadline = microtime(true) + 35;
        for ($redirect = 0; $redirect <= 5; $redirect++) {
            [$host, $port, $address, $literal] = $this->endpoint($url, $deadline);
            $remaining = (int) (($deadline - microtime(true)) * 1000);
            if ($remaining <= 0) { throw new \RuntimeException('The image download timed out.', 502); }
            $body = '';
            $location = null;
            $headerBytes = 0;
            $tooLarge = false;
            $handle = curl_init($url);
            if ($handle === false) { throw new \RuntimeException('Cannot start the image download.', 503); }
            $options = [
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_PROXY => '',
                CURLOPT_NOPROXY => '*',
                CURLOPT_CONNECTTIMEOUT_MS => min(8000, $remaining),
                CURLOPT_TIMEOUT_MS => $remaining,
                CURLOPT_LOW_SPEED_LIMIT => 128,
                CURLOPT_LOW_SPEED_TIME => 10,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT => 'unraid-icons/1.0',
                CURLOPT_HTTPHEADER => ['Accept: image/png,image/jpeg,image/webp,image/gif,application/json;q=0.9,*/*;q=0.1', 'Accept-Encoding: identity'],
                CURLOPT_WRITEFUNCTION => function ($curl, $chunk) use (&$body, &$tooLarge) {
                    if (strlen($body) + strlen($chunk) > self::MAX_BYTES) { $tooLarge = true; return 0; }
                    $body .= $chunk;
                    return strlen($chunk);
                },
                CURLOPT_HEADERFUNCTION => function ($curl, $header) use (&$location, &$headerBytes, &$tooLarge) {
                    $headerBytes += strlen($header);
                    if ($headerBytes > 32768) { $tooLarge = true; return 0; }
                    if (stripos($header, 'HTTP/') === 0) { $location = null; }
                    if (stripos($header, 'Location:') === 0) { $location = trim(substr($header, 9)); }
                    return strlen($header);
                },
            ];
            if (!$literal) {
                // Pin the validated address while retaining the hostname for TLS/SNI and Host.
                $options[CURLOPT_RESOLVE] = [$host.':'.$port.':'.(strpos($address, ':') !== false ? '['.$address.']' : $address)];
            }
            curl_setopt_array($handle, $options);
            try {
                $ok = curl_exec($handle);
                $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
                $peer = (string) curl_getinfo($handle, CURLINFO_PRIMARY_IP);
                if ($tooLarge) { throw new \RuntimeException('The response exceeds the 8 MiB image or 32 KiB header limit.', 400); }
                if ($ok === false) { throw new \RuntimeException('The remote server could not be reached securely: '.curl_error($handle), 502); }
                if (inet_pton($peer) !== inet_pton($address)) { throw new \RuntimeException('The remote address changed unexpectedly.', 502); }
            } finally { curl_close($handle); }
            if (in_array($status, [301, 302, 303, 307, 308], true)) {
                if ($location === null || $redirect === 5) { throw new \RuntimeException('The image URL redirected too many times or omitted its destination.', 502); }
                $url = $this->redirectUrl($url, $location);
                continue;
            }
            if ($status < 200 || $status >= 300) { throw new \RuntimeException('The remote server returned HTTP '.$status.'.', 502); }
            if ($body === '') { throw new \RuntimeException('The remote server returned an empty response.', 502); }
            return $body;
        }
        throw new \RuntimeException('Too many redirects.', 502);
    }

    private function redirectUrl(string $base, string $location): string
    {
        if (preg_match('/\A[a-z][a-z0-9+.-]*:/i', $location)) { return $location; }
        $parts = parse_url($base);
        if (substr($location, 0, 2) === '//') { return $parts['scheme'].':'.$location; }
        $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        if (substr($location, 0, 1) === '/') { return $origin.$location; }
        $path = $parts['path'] ?? '/';
        if (substr($location, 0, 1) === '?') { return $origin.$path.$location; }
        if (substr($location, 0, 1) === '#') { return $base; }
        return $origin.substr($path, 0, strrpos($path, '/') + 1).$location;
    }

    /** A failed/incomplete source poisons the whole scan; known references remain visible. */
    private function usage(): array
    {
        $usage = ['known' => true, 'references' => []];
        $this->scanDeadline = microtime(true) + 20;
        $add = function ($value, string $description) use (&$usage) {
            if (!is_string($value)) { throw new \RuntimeException('Invalid icon reference.'); }
            $path = parse_url($value, PHP_URL_PATH);
            if ($path === false) { throw new \RuntimeException('Invalid icon reference URL.'); }
            $filename = basename(rawurldecode($path ?? ''));
            if (preg_match(self::FILE_PATTERN, $filename)) {
                $usage['references'][$filename][] = $description;
            }
        };
        try {
            $uuids = $this->command([$this->paths['virsh'], '--connect', 'qemu:///system', 'list', '--all', '--uuid']);
            $uuids = preg_split('/\s+/', trim($uuids), -1, PREG_SPLIT_NO_EMPTY);
            if (count($uuids) > 2048) { throw new \RuntimeException('VM scan exceeded its safety limit.'); }
            foreach ($uuids as $uuid) {
                if (!preg_match('/\A[0-9a-f-]{36}\z/Di', $uuid)) { throw new \RuntimeException('Invalid VM list.'); }
                foreach ([false, true] as $inactive) {
                    $args = [$this->paths['virsh'], '--connect', 'qemu:///system', 'dumpxml', $uuid];
                    if ($inactive) { $args[] = '--inactive'; }
                    $xml = $this->xml($this->command($args));
                    if ($xml->documentElement->localName !== 'domain') { throw new \RuntimeException('Invalid VM definition.'); }
                    $xpath = new \DOMXPath($xml);
                    $name = $xpath->evaluate('string(/domain/name)') ?: $uuid;
                    foreach ($xpath->query('/domain/metadata/*[local-name()="vmtemplate"]/@icon') as $icon) {
                        $add($icon->nodeValue, 'VM: '.$name.($inactive ? ' (saved definition)' : ''));
                    }
                }
            }
        } catch (\Throwable $error) { $usage['known'] = false; }
        try {
            $path = $this->paths['vmTemplates'];
            if (file_exists($path) || is_link($path)) {
                if (is_link($path)) { throw new \RuntimeException('Unsafe saved VM templates.'); }
                $templates = json_decode($this->readBounded($path), true);
                if (!is_array($templates)) { throw new \RuntimeException('Invalid saved VM templates.'); }
                foreach ($templates as $name => $template) {
                    if (!is_array($template)) { throw new \RuntimeException('Invalid VM template.'); }
                    $add($template['icon'] ?? '', 'VM template: '.$name);
                    $add($template['overrides']['template']['icon'] ?? '', 'VM template: '.$name);
                }
            }
        } catch (\Throwable $error) { $usage['known'] = false; }
        foreach ($this->paths['dockerTemplates'] as $root) {
            try {
                if (!file_exists($root) && !is_link($root)) { continue; }
                if (!is_dir($root) || is_link($root)) { throw new \RuntimeException('Unsafe Docker template directory.'); }
                $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
                $count = 0;
                foreach ($iterator as $file) {
                    if (++$count > 10000 || $file->isLink() || microtime(true) > $this->scanDeadline) {
                        throw new \RuntimeException('Incomplete Docker template scan.');
                    }
                    if (!$file->isFile() || strtolower($file->getExtension()) !== 'xml') { continue; }
                    $xml = $this->xml($this->readBounded($file->getPathname()));
                    if ($xml->documentElement->localName !== 'Container') { throw new \RuntimeException('Invalid Docker template.'); }
                    $xpath = new \DOMXPath($xml);
                    $name = $xpath->evaluate('string(/Container/Name)') ?: $file->getBasename();
                    foreach ($xpath->query('/Container/Icon') as $icon) { $add($icon->nodeValue, 'Docker template: '.$name); }
                }
            } catch (\Throwable $error) { $usage['known'] = false; }
        }
        try {
            // Inspect every container, including stopped/orphaned containers without a saved template.
            $ids = $this->command([$this->paths['docker'], 'ps', '-aq', '--no-trunc']);
            $ids = preg_split('/\s+/', trim($ids), -1, PREG_SPLIT_NO_EMPTY);
            if (count($ids) > 2048) { throw new \RuntimeException('Container scan exceeded its safety limit.'); }
            foreach ($ids as $id) {
                if (!preg_match('/\A[0-9a-f]{12,64}\z/D', $id)) { throw new \RuntimeException('Invalid container list.'); }
                $containers = json_decode($this->command([$this->paths['docker'], 'inspect', '--type', 'container', $id]), true);
                if (!is_array($containers) || count($containers) !== 1 || !isset($containers[0]['Config']) || !is_array($containers[0]['Config'])) {
                    throw new \RuntimeException('Invalid container inspection.');
                }
                $container = $containers[0];
                $add($container['Config']['Labels']['net.unraid.docker.icon'] ?? '', 'Docker container: '.ltrim($container['Name'] ?? $id, '/'));
            }
        } catch (\Throwable $error) { $usage['known'] = false; }
        return $usage;
    }

    private function xml(string $text): \DOMDocument
    {
        if (!class_exists('DOMDocument') || stripos($text, '<!DOCTYPE') !== false || stripos($text, '<!ENTITY') !== false) {
            throw new \RuntimeException('Cannot safely parse XML definitions.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new \DOMDocument();
            $document->resolveExternals = false;
            $document->substituteEntities = false;
            if (!$document->loadXML($text, LIBXML_NONET) || !$document->documentElement || $document->doctype !== null) {
                throw new \RuntimeException('Invalid XML definition.');
            }
            return $document;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function command(array $arguments, ?float $deadline = null): string
    {
        if (!function_exists('proc_open') || !is_executable($arguments[0])) { throw new \RuntimeException('Reference service is unavailable.'); }
        $deadline = min(microtime(true) + 8, $deadline ?? $this->scanDeadline);
        if (microtime(true) > $deadline) { throw new \RuntimeException('The subprocess deadline expired.'); }
        $pipes = [];
        $process = @proc_open($arguments, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) { throw new \RuntimeException('Cannot inspect reference service.'); }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $exit = -1;
        try {
            do {
                $stdout .= stream_get_contents($pipes[1], 65536);
                $stderr .= stream_get_contents($pipes[2], 65536);
                if (strlen($stdout) + strlen($stderr) > self::MAX_BYTES || microtime(true) > $deadline) {
                    throw new \RuntimeException('Reference inspection exceeded its safety limit.');
                }
                $status = proc_get_status($process);
                if (!$status['running']) {
                    $exit = $status['exitcode'];
                    $stdout .= stream_get_contents($pipes[1], self::MAX_BYTES + 1);
                    $stderr .= stream_get_contents($pipes[2], self::MAX_BYTES + 1);
                    if (strlen($stdout) + strlen($stderr) > self::MAX_BYTES || !feof($pipes[1]) || !feof($pipes[2])) {
                        throw new \RuntimeException('Reference service output was incomplete or too large.');
                    }
                    break;
                }
                usleep(10000);
            } while (true);
        } finally {
            if ($exit === -1) { proc_terminate($process, 9); }
            fclose($pipes[1]);
            fclose($pipes[2]);
            $closed = proc_close($process);
            if ($exit === -1) { $exit = $closed; }
        }
        if ($exit !== 0 || trim($stderr) !== '') { throw new \RuntimeException('Reference service did not return a complete result.'); }
        return $stdout;
    }
}
