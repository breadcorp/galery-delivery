<?php
declare(strict_types=1);

require __DIR__ . '/../app/helpers.php';
require __DIR__ . '/../app/storage.php';
require __DIR__ . '/../app/rate_limit.php';
require __DIR__ . '/../app/setup.php';

function t_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('Assertion failed: ' . $message);
    }
}

function t_assert_throws(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (Throwable) {
        return;
    }
    throw new RuntimeException('Assertion failed: ' . $message);
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}

function all_php_files(string $root): array
{
    $files = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $item) {
        if ($item->isFile() && strtolower($item->getExtension()) === 'php') {
            $files[] = $item->getPathname();
        }
    }
    sort($files);
    return $files;
}

function assert_require_targets_exist(string $repoRoot): void
{
    foreach (all_php_files($repoRoot) as $file) {
        $src = (string) file_get_contents($file);
        if (!preg_match_all('/\b(?:require|require_once)\s+([^;]+);/', $src, $matches, PREG_SET_ORDER)) {
            continue;
        }

        foreach ($matches as $m) {
            $expr = trim((string) $m[1]);
            $target = null;

            if (preg_match('/^__DIR__\s*\.\s*[\'\"]([^\'\"]+)[\'\"]$/', $expr, $mm)) {
                $target = dirname($file) . '/' . $mm[1];
            } elseif (preg_match('/^dirname\(__DIR__\)\s*\.\s*[\'\"]([^\'\"]+)[\'\"]$/', $expr, $mm)) {
                $target = dirname(dirname($file)) . '/' . $mm[1];
            } elseif (preg_match('/^[\'\"]([^\'\"]+)[\'\"]$/', $expr, $mm)) {
                $target = dirname($file) . '/' . $mm[1];
            }

            if ($target !== null) {
                t_assert(is_file($target), 'Missing required PHP file: ' . $target . ' (from ' . $file . ')');
            }
        }
    }
}

function assert_bootstrap_loads(string $downloadsRoot): void
{
    require $downloadsRoot . '/app/bootstrap.php';
    t_assert(isset($config) && is_array($config), 'Bootstrap should load and provide config array');
}

$testRoot = str_replace('\\', '/', sys_get_temp_dir()) . '/gallery-delivery-v020-tests-' . bin2hex(random_bytes(4));
$diskA = $testRoot . '/disk-a';
$diskB = $testRoot . '/disk-b';
$downloadsRoot = dirname(__DIR__);

@mkdir($diskA, 0770, true);
@mkdir($diskB, 0770, true);

$config = [
    'storage_root' => $diskA,
    'storage_disks' => [$diskA, $diskB],
    'base_path' => '/downloads',
    'public_origin' => 'https://gallery.example',
    'max_photo_bytes' => 200 * 1024 * 1024,
    'max_background_bytes' => 250 * 1024 * 1024,
    'app_secret' => base64_encode(random_bytes(32)),
    'gallery_unlock_ttl' => 3600,
];

$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SESSION = [];

try {
    // Repository integrity checks.
    assert_require_targets_exist($downloadsRoot);
    assert_bootstrap_loads($downloadsRoot);
    t_assert(ini_get('session.use_strict_mode') === '1', 'Session strict mode must be enabled');
    t_assert(ini_get('session.use_only_cookies') === '1', 'Session IDs must only be accepted from cookies');

    $requiredFunctions = [
        'route_path', 'request_method', 'app_is_installed', 'setup_checks', 'setup_has_blockers',
        'verify_csrf', 'complete_initial_setup', 'render_setup_page', 'redirect_to', 'ensure_storage_layout',
        'is_admin', 'render_header', 'render_footer', 'render_flashes', 'list_galleries', 'base_url',
        'csrf_token', 'format_bytes', 'format_last_access', 'flash', 'rate_limit_remaining_seconds',
        'rate_limit_reset', 'rate_limit_register_failure', 'require_admin', 'random_password',
        'is_primary_admin', 'require_primary_admin', 'normalize_admin_username', 'is_valid_admin_username', 'admin_users', 'admin_roles', 'admin_can',
        'create_gallery', 'load_gallery', 'gallery_admin_password', 'absolute_url', 'current_background',
        'rename_gallery', 'save_gallery_background', 'save_background_upload', 'save_background_url',
        'gallery_record_event', 'record_gallery_activity', 'gallery_activity_log', 'load_gallery_selection',
        'selected_gallery_files', 'set_gallery_selection', 'set_gallery_unlocked', 'gallery_is_unlocked', 'send_file_response',
        'ensure_gallery_zip_ready', 'build_gallery_zip', 'build_gallery_selection_zip', 'remove_photo', 'delete_gallery', 'aperture_icon',
        'csv_safe_value',
    ];
    foreach ($requiredFunctions as $fn) {
        t_assert(function_exists($fn), 'Expected function is missing: ' . $fn);
    }

    ensure_storage_layout($config);

    t_assert(
        app_settings_path($config) === $diskA . '/_app/settings.json',
        'Application settings must live on the writable storage disk'
    );

    // Administrator accounts: old single-password installations migrate to permanent admin.
    $legacyAdminHash = secure_password_hash('LegacyAdmin123');
    $legacyUsers = admin_users(['admin_password_hash' => $legacyAdminHash]);
    t_assert(isset($legacyUsers['admin']), 'Legacy admin hash must migrate to the admin account');
    t_assert(password_verify('LegacyAdmin123', (string) $legacyUsers['admin']['password_hash']), 'Migrated admin password must verify');
    t_assert($legacyUsers['admin']['role'] === 'full', 'Primary admin must retain full access');
    t_assert(normalize_admin_username(' Editor.One ') === 'editor.one', 'Admin usernames must normalize consistently');
    t_assert(is_valid_admin_username('editor.one'), 'Valid administrator username should be accepted');
    t_assert(!is_valid_admin_username('ab'), 'Short administrator username should be rejected');
    t_assert(csv_safe_value('=1+1') === "'=1+1", 'CSV formula cells must be neutralized');
    t_assert(csv_safe_value('photo.jpg') === 'photo.jpg', 'Normal CSV values should remain unchanged');
    $_SERVER['HTTP_HOST'] = 'attacker.example';
    $_SERVER['SERVER_NAME'] = 'downloads.example';
    t_assert(absolute_url($config, 'sample') === 'https://gallery.example/downloads/sample', 'Public links must use the configured canonical origin, not the request Host header');
    $fallbackOriginConfig = array_replace($config, ['public_origin' => '']);
    t_assert(absolute_url($fallbackOriginConfig, 'sample') === 'https://downloads.example/downloads/sample', 'Fallback public links must use SERVER_NAME rather than the request Host header');

    // Multi-disk: create on A then on B, both must remain accessible.
    $galleryA = [
        'id' => uuid_v4(),
        'name' => 'Disk A Gallery',
        'password_hash' => secure_password_hash('PasswordA123'),
        'password_admin' => '',
        'created_at' => date(DATE_ATOM),
        'updated_at' => date(DATE_ATOM),
        'active' => true,
        'auth_version' => 1,
        'background' => ['mode' => 'global'],
        'files' => [],
        'zip' => ['ready' => false, 'dirty' => true, 'updated_at' => null, 'size' => 0],
        'storage' => ['file_count' => 0, 'original_bytes' => 0, 'preview_bytes' => 0, 'zip_bytes' => 0, 'updated_at' => date(DATE_ATOM)],
    ];
    $dirA = gallery_dir($config, (string) $galleryA['id'], true);
    @mkdir($dirA . '/originals', 0770, true);
    @mkdir($dirA . '/previews', 0770, true);
    write_json_file($dirA . '/gallery.json', $galleryA);

    $config['storage_root'] = $diskB;
    ensure_storage_layout($config);
    $galleryB = [
        'id' => uuid_v4(),
        'name' => 'Disk B Gallery',
        'password_hash' => secure_password_hash('PasswordB123'),
        'password_admin' => '',
        'created_at' => date(DATE_ATOM),
        'updated_at' => date(DATE_ATOM),
        'active' => true,
        'auth_version' => 1,
        'background' => ['mode' => 'global'],
        'files' => [],
        'zip' => ['ready' => false, 'dirty' => true, 'updated_at' => null, 'size' => 0],
        'storage' => ['file_count' => 0, 'original_bytes' => 0, 'preview_bytes' => 0, 'zip_bytes' => 0, 'updated_at' => date(DATE_ATOM)],
    ];
    $dirBCreate = gallery_dir($config, (string) $galleryB['id'], true);
    @mkdir($dirBCreate . '/originals', 0770, true);
    @mkdir($dirBCreate . '/previews', 0770, true);
    write_json_file($dirBCreate . '/gallery.json', $galleryB);

    t_assert(load_gallery($config, (string) $galleryA['id']) !== null, 'Gallery on inactive disk A should still be loadable');
    t_assert(load_gallery($config, (string) $galleryB['id']) !== null, 'Gallery on active disk B should be loadable');

    $listed = list_galleries($config);
    $ids = array_map(static fn(array $g): string => (string) ($g['id'] ?? ''), $listed);
    t_assert(in_array((string) $galleryA['id'], $ids, true), 'Dashboard listing must include gallery from disk A');
    t_assert(in_array((string) $galleryB['id'], $ids, true), 'Dashboard listing must include gallery from disk B');

    // New gallery creation must use active disk.
    if (extension_loaded('openssl')) {
        $createdOnActive = create_gallery($config, 'CreatedOnB123', 'Created on active disk B');
        $createdPath = gallery_dir($config, (string) $createdOnActive['id']);
    } else {
        $candidateUuid = uuid_v4();
        $createdPath = gallery_dir($config, $candidateUuid, true);
    }
    t_assert(str_starts_with(str_replace('\\\\', '/', $createdPath), str_replace('\\\\', '/', $diskB) . '/'), 'New gallery must be created on active disk B');

    // Duplicate UUID conflict must be detected safely.
    $duplicateUuid = uuid_v4();
    $dupA = $diskA . '/' . $duplicateUuid;
    $dupB = $diskB . '/' . $duplicateUuid;
    @mkdir($dupA . '/originals', 0770, true);
    @mkdir($dupA . '/previews', 0770, true);
    @mkdir($dupB . '/originals', 0770, true);
    @mkdir($dupB . '/previews', 0770, true);
    write_json_file($dupA . '/gallery.json', [
        'id' => $duplicateUuid,
        'password_hash' => secure_password_hash('x'),
        'created_at' => date(DATE_ATOM),
        'updated_at' => date(DATE_ATOM),
        'files' => [],
        'zip' => ['ready' => false, 'dirty' => true, 'updated_at' => null, 'size' => 0],
    ]);
    write_json_file($dupB . '/gallery.json', [
        'id' => $duplicateUuid,
        'password_hash' => secure_password_hash('x'),
        'created_at' => date(DATE_ATOM),
        'updated_at' => date(DATE_ATOM),
        'files' => [],
        'zip' => ['ready' => false, 'dirty' => true, 'updated_at' => null, 'size' => 0],
    ]);
    t_assert_throws(static fn() => find_gallery_storage($config, $duplicateUuid), 'Duplicate gallery UUID across disks must fail safely');

    // Auth: unlock + invalidation by auth_version.
    $galleryA = load_gallery($config, (string) $galleryA['id']);
    t_assert($galleryA !== null, 'Reload gallery A');
    t_assert(password_verify('PasswordA123', (string) $galleryA['password_hash']), 'Correct password verifies');
    t_assert(!password_verify('wrong', (string) $galleryA['password_hash']), 'Wrong password rejected');

    gallery_record_event($config, $galleryA, 'unlock');
    gallery_record_event($config, $galleryA, 'photo_download', [
        'photo_id' => 'a1',
        'photo_name' => 'sample.jpg',
    ]);
    $activity = gallery_activity_log($config, $galleryA);
    t_assert(count($activity) === 2, 'Unlocks and downloads should be present in the activity log');
    t_assert($activity[0]['event'] === 'photo_download', 'Activity log should return newest events first');
    t_assert($activity[0]['photo_name'] === 'sample.jpg', 'Photo download activity should include the photo name');
    t_assert((new DateTimeImmutable((string) $activity[0]['at'])) !== false, 'Activity timestamps should be valid ISO dates');

    $sessionIdBeforeUnlock = session_id();
    set_gallery_unlocked($galleryA);
    t_assert(session_id() !== $sessionIdBeforeUnlock, 'Unlocking a gallery must rotate the session ID');
    t_assert(gallery_is_unlocked($config, $galleryA), 'Gallery unlock should succeed');

    // Unlock TTL should expire.
    $key = gallery_session_key((string) $galleryA['id']);
    t_assert(isset($_SESSION[$key]) && is_array($_SESSION[$key]), 'Unlock session payload should exist');
    $_SESSION[$key]['unlocked_at'] = time() - 301;
    $ttlConfig = $config;
    $ttlConfig['gallery_unlock_ttl'] = 300;
    t_assert(!gallery_is_unlocked($ttlConfig, $galleryA), 'Unlock session should expire after TTL');

    set_gallery_unlocked($galleryA);
    t_assert(gallery_is_unlocked($config, $galleryA), 'Gallery unlock should work again after re-unlock');

    $galleryA['auth_version'] = gallery_auth_version($galleryA) + 1;
    t_assert(!gallery_is_unlocked($config, $galleryA), 'Unlock session must be invalid after auth_version change');

    // Rate limiting persists outside session.
    rate_limit_reset($config, 'gallery_unlock', (string) $galleryA['id']);
    for ($i = 0; $i < 8; $i++) {
        rate_limit_register_failure($config, 'gallery_unlock', (string) $galleryA['id']);
    }
    $_SESSION = [];
    $remaining = rate_limit_remaining_seconds($config, 'gallery_unlock', (string) $galleryA['id']);
    t_assert($remaining > 0, 'Rate limit must persist independently from session');
    rate_limit_reset($config, 'gallery_unlock', (string) $galleryA['id']);

    // Prepare files for ZIP and storage stats checks.
    $galleryB = load_gallery($config, (string) $galleryB['id']);
    t_assert($galleryB !== null, 'Reload gallery B');
    $dirB = gallery_dir($config, (string) $galleryB['id']);

    $photo1 = $dirB . '/originals/a.jpg';
    $photo2 = $dirB . '/originals/b.jpg';
    file_put_contents($photo1, str_repeat('A', 4096));
    file_put_contents($photo2, str_repeat('B', 8192));
    file_put_contents($dirB . '/previews/a.jpg', str_repeat('P', 1024));

    $galleryB['files'] = [
        ['id' => 'a1', 'stored_name' => 'a.jpg', 'original_name' => 'a.jpg', 'mime' => 'image/jpeg', 'size' => 4096, 'preview' => 'a.jpg'],
        ['id' => 'b2', 'stored_name' => 'b.jpg', 'original_name' => 'b.jpg', 'mime' => 'image/jpeg', 'size' => 8192, 'preview' => null],
    ];
    $selection = set_gallery_selection($config, $galleryB, 'a1', true);
    t_assert($selection['photo_ids'] === ['a1'], 'Client selection should persist the selected photo ID');
    t_assert(count(selected_gallery_files($config, $galleryB)) === 1, 'Selected-photo lookup should return only selected files');
    t_assert_throws(static fn() => set_gallery_selection($config, $galleryB, 'missing', true), 'A client cannot select a photo outside the gallery');
    $galleryB = refresh_gallery_storage_stats($config, $galleryB, false);
    $galleryB = mark_gallery_zip_dirty($config, $galleryB);
    save_gallery($config, $galleryB);
    t_assert(!is_file($dirB . '/full.zip'), 'Changing photos must not eagerly rebuild ZIP');

    $galleryB = load_gallery($config, (string) $galleryB['id']);
    t_assert(!empty($galleryB['zip']['dirty']), 'ZIP should be marked dirty after change');

    if (class_exists('ZipArchive')) {
        $galleryB = ensure_gallery_zip_ready($config, $galleryB);
        $zipPath = gallery_dir($config, (string) $galleryB['id']) . '/full.zip';
        t_assert(is_file($zipPath), 'Download-all should generate ZIP when outdated');
        t_assert(empty($galleryB['zip']['dirty']) && !empty($galleryB['zip']['ready']), 'ZIP metadata should be current after build');

        $mtimeBefore = (int) filemtime($zipPath);
        $galleryB = ensure_gallery_zip_ready($config, $galleryB);
        $mtimeAfter = (int) filemtime($zipPath);
        t_assert($mtimeAfter === $mtimeBefore, 'Existing valid ZIP should be reused');

        $zipOpen = new ZipArchive();
        t_assert($zipOpen->open($zipPath) === true, 'Generated ZIP must be readable');
        $zipOpen->close();
        t_assert(!(bool) glob($dirB . '/full.zip.tmp-*'), 'Temporary ZIP artifacts must not remain after successful build');

        $selectionZipPath = build_gallery_selection_zip($config, $galleryB);
        $selectionZip = new ZipArchive();
        t_assert($selectionZip->open($selectionZipPath) === true, 'Selection ZIP must be readable');
        t_assert($selectionZip->numFiles === 1, 'Selection ZIP must contain only selected photos');
        t_assert($selectionZip->getNameIndex(0) === 'a.jpg', 'Selection ZIP should preserve original photo names');
        $selectionZip->close();
        unlink($selectionZipPath);
    }

    // Delete should decrease counts and mark zip outdated.
    $oldZipMtime = is_file($dirB . '/full.zip') ? (int) filemtime($dirB . '/full.zip') : 0;
    $galleryB = remove_photo($config, $galleryB, 'a1');
    t_assert(count($galleryB['files']) === 1, 'Photo delete should remove item');
    t_assert(load_gallery_selection($config, $galleryB)['photo_ids'] === [], 'Deleting a photo should remove it from the client selection');
    t_assert((int) ($galleryB['storage']['file_count'] ?? 0) === 1, 'File count cache should decrease after delete');
    t_assert(!empty($galleryB['zip']['dirty']), 'ZIP must be marked outdated after delete');
    t_assert(!is_file($dirB . '/full.zip'), 'Deleting a photo should not rebuild ZIP immediately');
    if ($oldZipMtime > 0) {
        t_assert(!is_file($dirB . '/full.zip') || (int) filemtime($dirB . '/full.zip') === $oldZipMtime, 'Delete operation must not perform eager ZIP rebuild');
    }

    // Operations should target gallery real disk, not only active disk.
    $galleryA = load_gallery($config, (string) $galleryA['id']);
    t_assert($galleryA !== null, 'Reload gallery A for cross-disk operation test');
    $dirAReal = gallery_dir($config, (string) $galleryA['id']);
    $aOriginal = $dirAReal . '/originals/real-a.jpg';
    file_put_contents($aOriginal, str_repeat('R', 512));
    $galleryA['files'] = [[
        'id' => 'ra1',
        'stored_name' => 'real-a.jpg',
        'original_name' => 'real-a.jpg',
        'mime' => 'image/jpeg',
        'size' => 512,
        'preview' => null,
    ]];
    $galleryA = refresh_gallery_storage_stats($config, $galleryA, false);
    save_gallery($config, $galleryA);
    $galleryA = remove_photo($config, $galleryA, 'ra1');
    t_assert(!is_file($aOriginal), 'Delete must target original file on gallery real disk');

    // Old-gallery stats bootstrap: remove storage and force reload.
    $jsonPathB = gallery_json_path($config, (string) $galleryB['id']);
    $raw = read_json_file($jsonPathB);
    t_assert(is_array($raw), 'Gallery B JSON should be readable for stats bootstrap test');
    unset($raw['storage']);
    write_json_file($jsonPathB, $raw);
    $galleryB = load_gallery($config, (string) $galleryB['id']);
    t_assert(isset($galleryB['storage']['file_count']), 'Old gallery without cached stats should be initialized');

    // Repeated unchanged load should not rewrite gallery JSON repeatedly.
    clearstatcache(true, $jsonPathB);
    $mtime1 = (int) filemtime($jsonPathB);
    usleep(200000);
    $galleryB = load_gallery($config, (string) $galleryB['id']);
    clearstatcache(true, $jsonPathB);
    $mtime2 = (int) filemtime($jsonPathB);
    t_assert($mtime2 === $mtime1, 'Repeated load of unchanged gallery should reuse cached stats without rewriting gallery.json');

    echo "OK: all smoke checks passed\n";
} finally {
    rrmdir($testRoot);
}
