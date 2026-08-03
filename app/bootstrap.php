<?php
declare(strict_types=1);

$config = require dirname(__DIR__) . '/config.php';
$localConfig = dirname(__DIR__) . '/config.local.php';
if (is_file($localConfig)) {
    $override = require $localConfig;
    if (is_array($override)) {
        $config = array_replace($config, $override);
    }
}

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/views.php';
require_once __DIR__ . '/setup.php';

// One-time setup data lives on the SSD, so the uploaded application can stay read-only.
$settings = load_app_settings($config);

// Upgrade older installations automatically: create an encryption secret once on the SSD.
if (!empty($settings['setup_complete']) && !empty($settings['admin_password_hash']) && empty($settings['app_secret'])) {
    $settings['app_secret'] = base64_encode(random_bytes(32));
    $settings['updated_at'] = date(DATE_ATOM);
    write_app_settings($config, $settings);
}

if ($settings) {
    $storageDisks = array_values(array_unique(array_filter(array_map(fn($value) => trim((string) $value), (array) ($settings['storage_disks'] ?? [])), fn($value) => $value !== '')));
    $config = array_replace($config, [
        'setup_complete' => !empty($settings['setup_complete']),
        'admin_password_hash' => (string) ($settings['admin_password_hash'] ?? ''),
        'app_secret' => (string) ($settings['app_secret'] ?? $config['app_secret'] ?? ''),
        'storage_root' => resolve_storage_root($config, $settings),
        'storage_disks' => $storageDisks,
        'active_storage_disk' => (string) ($settings['active_storage_disk'] ?? ''),
        'app_name' => (string) ($settings['app_name'] ?? $config['app_name'] ?? 'Downloads'),
    ]);
}

date_default_timezone_set((string) $config['timezone']);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name((string) $config['session_name']);
    session_set_cookie_params([
        'httponly' => true,
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'samesite' => 'Lax',
        'path' => ($config['base_path'] ?: '/') . '/',
    ]);
    session_start();
}
