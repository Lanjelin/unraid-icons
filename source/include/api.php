<?php
// Unraid authenticates plugin URLs through nginx; local_prepend validates POST
// CSRF tokens and removes them before this file runs. Do not demand them twice.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) { return false; }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        throw new RuntimeException('Use POST for the icon API.', 405);
    }

    // PHP discards form fields when post_max_size is exceeded. Detect that
    // before CSRF/action parsing so the client gets an actionable JSON error.
    $iniBytes = function (string $value): int {
        $value = trim($value);
        if ($value === '' || $value === '0' || $value === '-1') { return 0; }
        $unit = strtolower(substr($value, -1));
        $number = (float) $value;
        $factor = ['k' => 1024, 'm' => 1048576, 'g' => 1073741824][$unit] ?? 1;
        return (int) min(PHP_INT_MAX, $number * $factor);
    };
    $contentLength = (float) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    $postLimit = $iniBytes((string) ini_get('post_max_size'));
    if ($contentLength > 9437184 || ($postLimit > 0 && $contentLength > $postLimit)) {
        throw new RuntimeException('The encoded PNG exceeds the request limit. Check PHP post_max_size and web-server body limits.', 413);
    }

    $csrfHandled = false;
    foreach (get_included_files() as $included) {
        if ($included === '/usr/local/emhttp/webGui/include/local_prepend.php' ||
            $included === '/usr/local/emhttp/plugins/dynamix/include/local_prepend.php') {
            $csrfHandled = true;
            break;
        }
    }
    if (!$csrfHandled) {
        // Fail closed on an installation without the normal auto-prepend setup,
        // while permitting its native token contract rather than disabling CSRF.
        $state = @parse_ini_file('/var/local/emhttp/var.ini');
        $expected = is_array($state) ? ($state['csrf_token'] ?? null) : null;
        $supplied = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        if (!is_string($expected) || $expected === '') {
            throw new RuntimeException('Unraid CSRF protection is unavailable.', 503);
        }
        if (!is_string($supplied) || !hash_equals($expected, $supplied)) {
            throw new RuntimeException('Invalid or missing CSRF token. Refresh the page and try again.', 403);
        }
        unset($_POST['csrf_token'], $_SERVER['HTTP_X_CSRF_TOKEN']);
    }

    require_once __DIR__.'/Icons.php';
    $parameter = function (string $key, string $default = ''): string {
        $value = $_POST[$key] ?? $default;
        if (!is_string($value)) { throw new RuntimeException('Invalid request parameter: '.$key, 400); }
        return $value;
    };
    $icons = new \UnraidIcons\Icons();
    switch ($parameter('action')) {
        case 'preferences':
            $result = ['ok' => true, 'preferences' => $icons->preferences()];
            break;
        case 'save-preferences':
            $enabled = $parameter('headerShortcut');
            if (!in_array($enabled, ['true', 'false'], true)) {
                throw new RuntimeException('Invalid header shortcut setting.', 400);
            }
            $result = ['ok' => true, 'preferences' => $icons->savePreferences($enabled === 'true')];
            break;
        case 'library':
            $result = ['ok' => true, 'icons' => $icons->library()];
            break;
        case 'catalog':
            $provider = $parameter('provider', 'selfhst');
            $collection = $parameter('collection', 'tabler');
            $result = ['ok' => true, 'icons' => $icons->catalog($provider, $collection)];
            if ($provider === 'iconify') { $result['attribution'] = $icons->iconifyAttribution($collection); }
            break;
        case 'download':
            $result = ['ok' => true, 'icon' => $icons->download($parameter('url'), $parameter('name'))];
            break;
        case 'save-iconify':
            $result = ['ok' => true, 'icon' => $icons->importIconifyBase64($parameter('png'), $parameter('iconId'), $parameter('color'))];
            break;
        case 'delete':
            $icons->delete($parameter('filename'));
            $result = ['ok' => true];
            break;
        default:
            throw new RuntimeException('Unknown icon API action.', 400);
    }
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
} catch (RuntimeException $error) {
    $status = $error->getCode();
    http_response_code(in_array($status, [400, 403, 404, 405, 409, 413, 500, 502, 503], true) ? $status : 500);
    echo json_encode(['ok' => false, 'error' => $error->getMessage()], JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $error) {
    error_log('unraid-icons: '.$error->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'The icon operation failed. Check the Unraid system log and flash permissions.']);
} finally {
    restore_error_handler();
}
