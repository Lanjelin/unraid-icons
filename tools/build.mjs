#!/usr/bin/env node
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const root = fileURLToPath(new URL('../', import.meta.url));
const changelog = await readFile(path.join(root, 'CHANGELOG.md'), 'utf8');
const release = changelog.trimStart().match(/^### (\d{4}\.\d{2}\.\d{2}[a-z]?)[ \t]*(?:\r?\n|$)/);
if (!release) throw new Error('CHANGELOG.md must start with a release heading: ### YYYY.MM.DD (optional lowercase letter suffix).');
const version = release[1];
const repository = 'https://github.com/lanjelin/unraid-icons';
const pluginURL = 'https://raw.githubusercontent.com/lanjelin/unraid-icons/main/dist/unraid-icons.plg';
const result = spawnSync('tar', ['--sort=name', '--mtime=2026-10-19T00:00:00Z', '--owner=0', '--group=0', '--numeric-owner', '-czf', '-', '-C', path.join(root, 'source'), '.', '-C', root, '--transform=s,^README.md$,README-full.md,', 'README.md', 'LICENSE'], { maxBuffer: 16 * 1024 * 1024 });
if (result.status !== 0) throw new Error(result.stderr.toString());
const payload = result.stdout.toString('base64').match(/.{1,76}/g).join('\n');
const lifecycle = await readFile(path.join(root, 'source/scripts/lifecycle'), 'utf8');
const install = `#!/bin/bash
set -euo pipefail
trap 'rm -f /tmp/unraid-icons-install.sh /tmp/unraid-icons-payload.tgz' EXIT
php -r 'foreach (["curl", "gd", "dom", "simplexml"] as $extension) { if (!extension_loaded($extension)) { fwrite(STDERR, "unraid-icons requires PHP extension: $extension\\n"); exit(1); } }'
base64 -d > /tmp/unraid-icons-payload.tgz <<'UNRAID_ICONS_PAYLOAD'
${payload}
UNRAID_ICONS_PAYLOAD
mkdir -p /usr/local/emhttp/plugins/unraid-icons
tar -xmzf /tmp/unraid-icons-payload.tgz -C /usr/local/emhttp/plugins/unraid-icons
rm -f /usr/local/emhttp/plugins/unraid-icons/CHANGELOG.md
chmod 0755 /usr/local/emhttp/plugins/unraid-icons/scripts/lifecycle
/bin/bash /usr/local/emhttp/plugins/unraid-icons/scripts/lifecycle install
`;
const cdata = value => `<![CDATA[\n${value.replaceAll(']]>', ']]]]><![CDATA[>')}\n]]>`;
const manifest = `<?xml version="1.0"?>
<PLUGIN name="unraid-icons" author="Lanjelin" version="${version}" launch="Settings/Icons" icon="unraid-icons.png" pluginURL="${pluginURL}" support="${repository}/issues">
<CHANGES>${cdata(changelog)}</CHANGES>
<FILE Name="/tmp/unraid-icons-install.sh" Run="/bin/bash"><INLINE>${cdata(install)}</INLINE></FILE>
<FILE Run="/bin/bash" Method="remove"><INLINE>${cdata(lifecycle.replace('case "${1:-}" in', 'case "remove" in'))}</INLINE></FILE>
</PLUGIN>
`;
await mkdir(path.join(root, 'dist'), { recursive: true });
await writeFile(path.join(root, 'dist/unraid-icons.plg'), manifest);
console.log(`Built dist/unraid-icons.plg (${Buffer.byteLength(manifest)} bytes); self-contained, no release server required.`);
