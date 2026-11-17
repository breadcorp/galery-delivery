<?php
declare(strict_types=1);
require __DIR__ . '/../app/helpers.php';
require __DIR__ . '/../app/storage.php';
require __DIR__ . '/../app/setup.php';
require __DIR__ . '/../app/rate_limit.php';
if (is_file(legacy_app_settings_path([]))) throw new RuntimeException('Run tests in an isolated source copy without real settings.');
function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function rejects(callable $call, string $message): void { try { $call(); } catch (Throwable) { return; } throw new RuntimeException($message); }
$root = str_replace('\\', '/', sys_get_temp_dir()) . '/gallery-v5-' . bin2hex(random_bytes(6));
mkdir($root, 0770, true);
$config = ['storage_root' => $root . '/disk', 'app_data_dir' => $root . '/control', 'gallery_unlock_ttl' => 86400];
$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
$_SERVER['REMOTE_ADDR'] = '192.0.2.9';
try {
    foreach ([
        [100, '', 200, 0, 100], [100, 'bytes=-10', 206, 90, 10],
        [100, 'bytes=-200', 206, 0, 100], [100, 'bytes=5-', 206, 5, 95],
        [100, 'bytes=5-200', 206, 5, 95], [100, 'bytes=0-0', 206, 0, 1],
        [100, 'bytes=100-', 416, 0, 0], [100, 'bytes=9-2', 416, 0, 0],
        [100, 'bytes=-0', 416, 0, 0], [0, '', 200, 0, 0],
        [0, 'bytes=0-', 416, 0, 0], [100, 'bytes=1-2,4-5', 200, 0, 100],
    ] as [$size, $header, $status, $start, $length]) {
        check(download_range($size, $header) === ['status' => $status, 'start' => $start, 'length' => $length], 'Range failed: ' . $header);
    }
    rejects(fn() => app_data_dir(['storage_root' => dirname(__DIR__)]), 'Public control data must be rejected');
    $settings = ['setup_complete' => true, 'admin_password_hash' => 'synthetic', 'app_secret' => base64_encode(random_bytes(32))];
    write_app_settings($config, $settings);
    check(!is_file(legacy_app_settings_path($config)), 'Writing settings recreated webroot secrets');
    $switched = array_replace($config, ['storage_root' => $root . '/disk-two']);
    check(load_app_settings($switched) === $settings, 'Changing photo disk changed settings location');
    file_put_contents(app_settings_path($config), '{broken');
    rejects(fn() => load_app_settings($config), 'Corrupt settings silently reopened setup');
    write_app_settings($config, $settings);

    // Migration runs in an isolated fake app, never in a real deployment.
    mkdir($root . '/fixture/app', 0770, true);
    copy(__DIR__ . '/../app/setup.php', $root . '/fixture/app/setup.php');
    file_put_contents($root . '/fixture/app/settings.json', json_encode($settings));
    $script = '<?php $fixturePath = __DIR__ . "/fixture/app/setup.php"; require $fixturePath; $c = ["storage_root" => __DIR__ . "/migrated"]; $a = load_app_settings($c); if (is_file(__DIR__ . "/fixture/app/settings.json") || empty($a["app_secret"])) exit(1); if ($a !== load_app_settings($c)) exit(2);';
    file_put_contents($root . '/migration.php', $script);
    $process = proc_open([PHP_BINARY, $root . '/migration.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    check(proc_close($process) === 0, 'Migration failed: ' . $output);

    $gallery = ['id' => uuid_v4(), 'auth_version' => 5];
    $_SESSION[gallery_session_key($gallery['id'])] = time();
    check(!gallery_is_unlocked($config, $gallery), 'Unversioned session survived password rotation');
    $_SESSION[gallery_session_key($gallery['id'])] = ['unlocked_at' => time() + 3600, 'auth_version' => 5];
    check(!gallery_is_unlocked($config, $gallery), 'Future session timestamp was accepted');

    // Exponential cooldown must reach the one-hour ceiling across fresh sessions.
    foreach ([300, 600, 1200, 2400, 3600] as $expected) {
        for ($i = 0; $i < 8; $i++) $cooldown = rate_limit_register_failure($config, 'admin_login');
        check($cooldown === $expected, 'Cooldown did not grow to ' . $expected);
        $_SESSION = [];
        check(rate_limit_remaining_seconds($config, 'admin_login') > 0, 'Fresh session bypassed limiter');
        $file = rate_limit_file($config, 'admin_login', '');
        $state = read_json_file($file); $state['locked_until'] = time() - 1; write_json_file($file, $state);
    }
    $_SERVER['REMOTE_ADDR'] = '192.0.2.10';
    check(rate_limit_remaining_seconds($config, 'admin_login') === 0, 'Independent IP was blocked');
    $_SERVER['REMOTE_ADDR'] = '192.0.2.9';
    rate_limit_reset($config, 'admin_login');
    check(rate_limit_remaining_seconds($config, 'admin_login') === 0, 'Successful login did not reset limiter');
    $stats = default_gallery_live_stats(['download_count' => 12]);
    check($stats['photo_downloads'] === 0 && $stats['legacy_downloads'] === 12, 'Unknown old downloads were misclassified');

    mkdir($config['storage_root'], 0770, true);
    file_put_contents($config['storage_root'] . '/photo.jpg', 'test');
    $offloadConfig = array_replace($config, ['download_backend' => 'x-accel', 'x_accel_mappings' => [$config['storage_root'] => '/_files/']]);
    check(download_offload_header($offloadConfig, $config['storage_root'] . '/photo.jpg') === ['X-Accel-Redirect', '/_files/photo.jpg'], 'Nginx mapping failed');
    rejects(fn() => download_offload_header($offloadConfig, __FILE__), 'Offload escaped private storage');
    echo "OK: v5 regression checks passed (ranges, migration, settings, sessions, limiter, stats, offload)\n";
} finally {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $entry) $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    rmdir($root);
}
