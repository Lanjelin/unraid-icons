# unraid-icons

A shared icon library for Unraid VMs and Docker containers, with optional Compose Manager Plus integration. Browse application logos and generic symbols, or import an image from a URL. Download an icon once and select it from supported editors.

[GitHub repository](https://github.com/lanjelin/unraid-icons) · [Report an issue](https://github.com/lanjelin/unraid-icons/issues)

## Install

### From the Unraid WebGUI

1. Use this direct plugin URL:

   ```text
   https://raw.githubusercontent.com/lanjelin/unraid-icons/main/dist/unraid-icons.plg
   ```
2. Open **Plugins → Install Plugin** in Unraid.
3. In **Enter URL of remote plugin file or local plugin file**, enter either:
   - The direct URL above, for installation from GitHub.
   - The full path to a copy on your Unraid server, such as `/tmp/unraid-icons.plg`. This is a server path, not a path on your computer.
4. Select **Install** and wait for installation to complete.
5. Reload the WebGUI, then open **Settings → Icons**.

The package is self-contained; installation does not fetch additional plugin code or packages. The installer checks the required PHP extensions and reports any missing dependencies rather than installing them automatically.

### From the terminal

Copy the plugin file to `/tmp/unraid-icons.plg` on your server, then run:

```sh
plugin install /tmp/unraid-icons.plg
```

Reload the WebGUI after installation.

## Download icons

Open **Settings → Icons → Add icon**, or select **Choose icon…** in a VM or Docker container editor.

### Browse an icon collection

1. Open **Browse icons** and choose an **Icon source**:
   - **selfh.st** — application and service logos.
   - **Dashboard Icons** — another collection of application and service logos.
   - **Iconify** — generic symbols from **Tabler Icons**, **Material Design Icons**, and **Phosphor**, including errors, 404 pages, runners, tools, and storage.
2. Search for an icon. Where offered, light and dark variants appear as separate results.
3. For Iconify, choose a collection and **Icon color** before downloading.
4. Select **Download** and wait for the saved confirmation.

Iconify icons are converted automatically in your browser into transparent 512 × 512 PNGs, then validated and stored on the server. No manual conversion, file upload dialog, or extra conversion service is needed. The chosen color is part of the saved image; it will not change when you switch Unraid themes. Download another color if needed.

### Import a custom image

Open **Custom URL**, enter a direct public image URL or paste a `data:image/png;base64,...` URL, optionally give it a name, and select **Download icon**. Public URLs must point to the image itself, not a webpage containing it.

- Supported formats: PNG, JPEG, GIF, and WebP for public URLs, subject to your server's image-format support. Data URLs support base64-encoded PNG only. All are saved as PNG; animated images become a single frame.
- Maximum image size: **8 MiB**, measured after base64 decoding for data URLs. Images must be no larger than **4096 pixels on either side** and **4,194,304 pixels in total**. Encoded data URLs must also fit PHP and web-server request-size limits.
- Data URLs are imported without an external download. Their base64 payload is not retained in library metadata; unnamed imports use **Custom icon**.
- Custom SVG URLs are not supported. Use a PNG version or one of the supported Iconify collections instead.
- Local/private network URLs and URLs with embedded login credentials are rejected. The plugin does not import images from private services on your LAN.

Only icons you explicitly download are saved. Browsing previews does not fill your library. A completed download stays in the library even if you close the picker without using it.

## Apply an icon to a VM or Docker container

1. Open the normal Unraid editor for the VM or container.
2. Select **Choose icon…** beside its icon field.
3. Choose an icon from **Saved icons**, or download one and select **Use icon**.
4. Use the editor's normal **Apply** button to save the change.

**Selecting an icon only changes the editor form.** Closing or canceling the editor without applying does not change the VM or container. Downloading from **Settings → Icons** adds to the shared library; it does not assign an icon to anything.

The same saved icon can be used by multiple VMs and containers. The plugin does not apply editor changes, redefine VMs, or recreate containers on your behalf.

### Compose Manager Plus (optional)

If [Compose Manager Plus](https://github.com/mstrhakr/compose_plugin) is installed, **Choose icon…** also appears in its stack editor:

- **Settings → Icon URL / Path** selects the stack icon.
- **WebUI Labels → Icon URL / Path** selects an individual service's container icon.

Choose a saved icon or download one, then select **Use icon** and use Compose Manager Plus's **Apply** button. Service-label changes may require recreating the affected containers; use Compose Manager Plus's normal workflow.

Selections are embedded as PNG data URLs in Compose's settings or service labels, not linked to the library file. This avoids private-host and authenticated-URL restrictions. Saved copies remain usable if you delete the library original or uninstall unraid-icons, and do not count as library-file references. Changing an icon in the library does not update those copies; select it again to replace them. Large embedded images must fit Compose Manager Plus and web-server request-size limits.

Compose Manager Plus is not required. Without it, the normal VM and Docker integrations work unchanged.

## Manage your library

Open **Settings → Icons** to search downloaded icons, see where they are used, and remove unused ones. Use **Refresh library** to check usage again after changing a VM, container, or template.

Cards show a compact collection label and usage status. The **Sources & licenses** footer lists credits and links once per represented collection or provider. It covers the whole saved library, even when a search hides some icons.

### Why is Remove disabled?

The plugin only allows removal when it can confirm that an icon is unused by **both VMs and Docker**.

- **In use by…** — a VM, container, or saved template still refers to the icon. Assign a different icon and Apply the change. Also update or remove any saved templates that still refer to it, then refresh the library.
- **Usage check incomplete; removal is disabled.** — the plugin could not check all references. Ensure both VM Manager and Docker are available, then refresh. A stopped service, timeout, or unreadable definition can prevent a complete check, even if you only use icons with one of the two services.

**Stopping a VM or container does not make its icon unused.** Checks include running and stopped VMs and containers, current and saved VM definitions, saved VM templates, and saved Docker templates. Removing a container alone may leave its Docker template referring to the icon.

To remove an unused icon, select **Remove** and confirm. The server checks usage again before deleting the PNG, its library metadata, and the links used to display it. There is no undo; download it again if needed.

Avoid applying an icon in one browser tab while removing it in another: an unapplied editor selection is not a saved reference. References in external backups or files outside Unraid's checked definitions and template locations cannot be detected.

## Header shortcut

**Show shortcut in header** is off by default. Toggle it on the Icons page to save and apply the setting immediately; there is no separate Save button. The preference survives reboots.

If saving fails, the toggle returns to its previous state and shows an error. Turning the shortcut off does not affect **Settings → Icons** or the editor pickers.

## Storage, updates, and reboots

Downloaded icons are stored on the Unraid boot device at:

```text
/boot/config/plugins/unraid-icons/icons
```

The storage location is fixed. Names, source URLs, attribution, and preferences are stored alongside the icons under `/boot/config/plugins/unraid-icons`. Include this directory in your boot-device backups. Source URLs are retained, so avoid URLs containing secret tokens or other sensitive query parameters.

- **After a reboot:** Unraid reinstalls the plugin from its saved package, and the plugin restores the links that make icons available to the WebGUI and native VM picker. Downloaded icons remain available without internet access or starting the array. Usage checks may still be incomplete until VM Manager and Docker are available.
- **When a provider is unavailable:** existing downloads remain usable. Internet access is only needed to browse remote collections or download new images.
- **When names collide:** existing icons are not overwritten; a suffix is added where needed. Reimporting the same image under the same available name can reuse the existing file.
- **When updating the plugin:** downloaded icons and settings are retained.

Open **Plugins → Check for Updates** to look for new versions, then select **Update** for **unraid-icons** when offered. Updates come from the published plugin file in the [GitHub repository](https://github.com/lanjelin/unraid-icons). Reload the WebGUI afterward. Versions installed before GitHub update support was added need one manual reinstall using the URL above to enable future update checks.

You can also install a downloaded `.plg` through **Plugins → Install Plugin** or the terminal command above. Use a separate copy such as `/tmp/unraid-icons.plg`; do not overwrite `/boot/config/plugins/unraid-icons.plg` before installing, as Unraid can skip reinstalling that same file. A Community Applications listing is not required to install or update this plugin.

## Troubleshooting

- **The picker or a recent change is missing:** reload the WebGUI after installing or updating, and reopen the VM or container editor.
- **A catalog or preview fails to load:** check connectivity and use **Retry catalog** or **Retry previews** when offered. Iconify requests are made by your browser, so content blockers can affect them. A preview failure does not necessarily prevent downloading.
- **A download fails or stalls:** check the displayed error and your connection. If you access Unraid through a reverse proxy, its timeout or request-size limits can also block saving. Refresh the library before retrying to see whether the icon was already stored.
- **An icon is saved but the VM/container did not change:** reopen its editor, select the saved icon, and use **Apply**. Downloading alone does not assign it.
- **An icon cannot be removed:** see [Why is Remove disabled?](#why-is-remove-disabled). Do not manually delete an in-use PNG to bypass the check.

## Privacy and licenses

Browsing and downloading contacts the selected provider. Catalogs are fetched by your server; previews load in your browser from GitHub or Iconify, exposing your browser's IP address to those services. No account or API key is required, but providers may impose rate limits.

Logos and icons remain subject to their respective licenses and trademark rights. Downloading an icon does not transfer ownership or grant trademark rights. Use the **Sources & licenses** links in your library to review the applicable terms.

## Uninstall

1. If you intend to stop using the library, assign replacement icons to affected VMs and containers and update any saved templates first.
2. Open Unraid's **Plugins** page and remove **unraid-icons**.

Uninstalling removes the plugin interface and its runtime links, but **keeps downloaded icons, metadata, and preferences** under `/boot/config/plugins/unraid-icons`. Reinstalling the plugin makes that retained library available again.

VM icons supplied by the plugin may fall back to Unraid's default after removal because their runtime links are gone. Existing Docker icon caches are not deleted, so a container may continue to show its cached icon; that does not mean the plugin is still installed.

For a complete cleanup, manually remove `/boot/config/plugins/unraid-icons` only after uninstalling, reassigning all references, and confirming you no longer need the downloads or settings. Deleting that directory permanently removes the retained library.

## Build a release

Requires Node.js and GNU `tar`. Run from the repository root:

```bash
node tools/build.mjs
```

The build creates the self-contained `dist/unraid-icons.plg`. Its version comes from the first heading in [CHANGELOG.md](CHANGELOG.md), the single source of truth for release versions. Before each release, prepend a heading in the form `### YYYY.MM.DD`, optionally with a lowercase letter suffix such as `### 2026.10.21a`, followed by concise user-facing changes. Keep older entries below it. A missing or malformed first heading fails the build.

The builder embeds the changelog in the plugin's `<CHANGES>` element so Unraid's plugin manager can display release notes. Do not edit the generated `.plg` by hand. Publish the rebuilt `dist/unraid-icons.plg` at the configured update URL.
