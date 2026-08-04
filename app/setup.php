<?php
declare(strict_types=1);

function app_settings_path(array $config): string
{
    return dirname(__DIR__) . '/app/settings.json';
}

function legacy_app_settings_path(array $config): string
{
    return rtrim((string) $config['storage_root'], '/') . '/_app/settings.json';
}

function load_app_settings(array $config): array
{
    $settingsPath = app_settings_path($config);
    if (!is_file($settingsPath)) {
        $legacyPath = legacy_app_settings_path($config);
        if (!is_file($legacyPath)) {
            return [];
        }
        $settingsPath = $legacyPath;
    }

    $decoded = json_decode((string) @file_get_contents($settingsPath), true);
    return is_array($decoded) ? $decoded : [];
}

function write_app_settings(array $config, array $settings): void
{
    $settingsPath = app_settings_path($config);
    $dir = dirname($settingsPath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }

    $json = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $tmp = $settingsPath . '.tmp-' . bin2hex(random_bytes(6));
    if (@file_put_contents($tmp, $json . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('The application settings could not be saved.');
    }
    @chmod($tmp, 0660);
    if (!@rename($tmp, $settingsPath)) {
        @unlink($tmp);
        throw new RuntimeException('The application settings could not be saved.');
    }

    $legacyPath = legacy_app_settings_path($config);
    $legacyDir = dirname($legacyPath);
    if (!is_dir($legacyDir)) {
        @mkdir($legacyDir, 0770, true);
    }
    $legacyTmp = $legacyPath . '.tmp-' . bin2hex(random_bytes(6));
    if (@file_put_contents($legacyTmp, $json . PHP_EOL, LOCK_EX) !== false) {
        @chmod($legacyTmp, 0660);
        if (!@rename($legacyTmp, $legacyPath)) {
            @unlink($legacyTmp);
        }
    }
}

function resolve_storage_root(array $config, array $settings): string
{
    $configured = trim((string) ($config['storage_root'] ?? ''));
    $disks = [];
    foreach ((array) ($settings['storage_disks'] ?? []) as $disk) {
        $disk = trim((string) $disk);
        if ($disk !== '') {
            $disks[] = $disk;
        }
    }

    if ($disks) {
        $selected = trim((string) ($settings['active_storage_disk'] ?? ''));
        if ($selected !== '' && in_array($selected, $disks, true)) {
            return $selected;
        }
        return $disks[0];
    }

    return $configured;
}

function app_is_installed(array $config): bool
{
    return !empty($config['setup_complete']) && !empty($config['admin_password_hash']);
}

function ini_bytes(string $value): int
{
    $value = trim($value);
    if ($value === '') {
        return 0;
    }
    $last = strtolower($value[strlen($value) - 1]);
    $number = (float) $value;
    return match ($last) {
        'g' => (int) round($number * 1024 * 1024 * 1024),
        'm' => (int) round($number * 1024 * 1024),
        'k' => (int) round($number * 1024),
        default => (int) round($number),
    };
}

function path_can_be_created(string $path): bool
{
    $path = rtrim($path, '/');
    if ($path === '') {
        return false;
    }
    if (is_dir($path)) {
        return is_writable($path);
    }
    $parent = dirname($path);
    while ($parent !== '/' && !is_dir($parent)) {
        $next = dirname($parent);
        if ($next === $parent) {
            break;
        }
        $parent = $next;
    }
    return is_dir($parent) && is_writable($parent);
}

function setup_checks(array $config): array
{
    $storageRoot = (string) $config['storage_root'];
    $uploadLimit = ini_bytes((string) ini_get('upload_max_filesize'));
    $postLimit = ini_bytes((string) ini_get('post_max_size'));
    $photoLimit = (int) $config['max_photo_bytes'];

    return [
        [
            'label' => 'PHP 8.2 or newer',
            'ok' => version_compare(PHP_VERSION, '8.2.0', '>='),
            'critical' => true,
            'detail' => 'Nalezeno PHP ' . PHP_VERSION,
        ],
        [
            'label' => 'OpenSSL extension',
            'ok' => extension_loaded('openssl'),
            'critical' => true,
            'detail' => 'Required for encrypted storage of gallery passwords in the admin area.',
        ],
        [
            'label' => 'fileinfo extension',
            'ok' => extension_loaded('fileinfo'),
            'critical' => true,
            'detail' => 'Required to validate uploaded photos.',
        ],
        [
            'label' => 'Write access to the storage disk',
            'ok' => path_can_be_created($storageRoot),
            'critical' => true,
            'detail' => $storageRoot,
        ],
        [
            'label' => 'ZIP extension',
            'ok' => class_exists('ZipArchive'),
            'critical' => false,
            'detail' => 'Without it, full.zip cannot be created.',
        ],
        [
            'label' => 'GD extension',
            'ok' => extension_loaded('gd'),
            'critical' => false,
            'detail' => 'Without it, thumbnail previews will not be created.',
        ],
        [
            'label' => 'Single-file upload limit of at least 200 MB',
            'ok' => $uploadLimit === 0 || $uploadLimit >= $photoLimit,
            'critical' => false,
            'detail' => 'Current: ' . (string) ini_get('upload_max_filesize'),
        ],
        [
            'label' => 'POST limit of at least 200 MB',
            'ok' => $postLimit === 0 || $postLimit >= $photoLimit,
            'critical' => false,
            'detail' => 'Current: ' . (string) ini_get('post_max_size'),
        ],
    ];
}

function setup_has_blockers(array $checks): bool
{
    foreach ($checks as $check) {
        if (!empty($check['critical']) && empty($check['ok'])) {
            return true;
        }
    }
    return false;
}

function complete_initial_setup(array $config, string $password): void
{
    ensure_storage_layout($config);

    $appDir = rtrim((string) $config['storage_root'], '/') . '/_app';
    $settingsPath = $appDir . '/settings.json';
    $lockPath = $appDir . '/setup.lock';
    $lock = @fopen($lockPath, 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        if (is_resource($lock)) {
            fclose($lock);
        }
        throw new RuntimeException('The installation could not be safely locked.');
    }
    @chmod($lockPath, 0660);

    try {
        $existing = read_json_file($settingsPath) ?? [];
        if (!empty($existing['setup_complete']) && !empty($existing['admin_password_hash'])) {
            throw new RuntimeException('Initial setup has already been completed.');
        }

        $settings = array_merge(load_app_settings($config), [
            'setup_complete' => true,
            'admin_password_hash' => secure_password_hash($password),
            'app_secret' => base64_encode(random_bytes(32)),
            'installed_at' => date(DATE_ATOM),
            'storage_disks' => array_values(array_unique(array_filter([(string) $config['storage_root']], fn($disk) => $disk !== ''))),
            'active_storage_disk' => (string) $config['storage_root'],
            'app_name' => 'Downloads',
        ]);
        write_app_settings($config, $settings);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function render_setup_page(array $config, array $checks): void
{
    $blocked = setup_has_blockers($checks);
    render_header($config, 'Initial setup', false, 'setup-page');
    echo '<main class="setup-shell">';
    echo '<section class="setup-hero"><div class="setup-mark">' . aperture_icon(30) . '</div><div><p class="eyebrow">DOWNLOADS</p><h1>Initial setup</h1><p class="setup-lead">Set the administrator password once. After that the installation will be locked and the dashboard will open.</p></div></section>';
    render_flashes();
    echo '<section class="setup-grid"><div class="panel setup-info"><h2>Prepared structure</h2>';
    echo '<dl><div><dt>Admin</dt><dd><code>' . e(base_url($config, 'admin')) . '</code></dd></div>';
    echo '<div><dt>Galleries</dt><dd><code>' . e(base_url($config, '<uuid-gallery>')) . '</code></dd></div>';
    echo '<div><dt>SSD</dt><dd><code>' . e((string) $config['storage_root']) . '</code></dd></div></dl>';
    echo '<p class="setup-note">The admin password hash and installation lock will be stored on the storage disk in <code>_app/settings.json</code>. The uploaded application folder can remain read-only.</p></div>';
    echo '<div class="panel"><h2>Kontrola serveru</h2><div class="check-list">';
    foreach ($checks as $check) {
        $ok = !empty($check['ok']);
        $critical = !empty($check['critical']);
        $class = $ok ? 'ok' : ($critical ? 'bad' : 'warn');
        $symbol = $ok ? '✓' : ($critical ? '×' : '!');
        echo '<div class="check-item ' . $class . '"><span class="check-symbol">' . $symbol . '</span><div><strong>' . e((string) $check['label']) . '</strong><small>' . e((string) $check['detail']) . '</small></div></div>';
    }
    echo '</div></div></section>';
    echo '<section class="panel setup-password"><h2>Admin password</h2><p class="muted">At least 10 characters. This password is only for access to the dashboard; each gallery will have its own password.</p>';
    if ($blocked) {
        echo '<div class="flash error">The red-marked checks must be fixed first.</div>';
    }
    echo '<form method="post" action="' . e(base_url($config)) . '"><input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
    echo '<div class="password-grid"><label>New admin password<input type="password" name="password" minlength="10" maxlength="200" required autocomplete="new-password" autofocus></label>';
    echo '<label>Repeat password<input type="password" name="password_confirmation" minlength="10" maxlength="200" required autocomplete="new-password"></label></div>';
    echo '<button class="primary wide"' . ($blocked ? ' disabled' : '') . '>Complete setup and open admin</button></form>';
    echo '<p class="setup-lock">Once completed, this initial page will not be shown again.</p></section></main>';
    render_footer($config);
}
