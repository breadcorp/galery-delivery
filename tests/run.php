<?php
declare(strict_types=1);

require __DIR__ . '/../app/helpers.php';
require __DIR__ . '/../app/storage.php';
require __DIR__ . '/../app/rate_limit.php';

function t_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('Assertion failed: ' . $message);
    }
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

$testRoot = sys_get_temp_dir() . '/gallery-delivery-v020-tests-' . bin2hex(random_bytes(4));
$diskA = $testRoot . '/disk-a';
$diskB = $testRoot . '/disk-b';

@mkdir($diskA, 0770, true);
@mkdir($diskB, 0770, true);

$config = [
    'storage_root' => $diskA,
    'storage_disks' => [$diskA, $diskB],
    'base_path' => '/downloads',
    'max_photo_bytes' => 200 * 1024 * 1024,
    'max_background_bytes' => 250 * 1024 * 1024,
    'app_secret' => base64_encode(random_bytes(32)),
    'gallery_unlock_ttl' => 3600,
];

$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SESSION = [];

try {
    ensure_storage_layout($config);

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

    // Auth: unlock + invalidation by auth_version.
    $galleryA = load_gallery($config, (string) $galleryA['id']);
    t_assert($galleryA !== null, 'Reload gallery A');
    t_assert(password_verify('PasswordA123', (string) $galleryA['password_hash']), 'Correct password verifies');
    t_assert(!password_verify('wrong', (string) $galleryA['password_hash']), 'Wrong password rejected');

    set_gallery_unlocked($galleryA);
    t_assert(gallery_is_unlocked($config, $galleryA), 'Gallery unlock should succeed');

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
    $galleryB = refresh_gallery_storage_stats($config, $galleryB, false);
    $galleryB = mark_gallery_zip_dirty($config, $galleryB);
    save_gallery($config, $galleryB);

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
    }

    // Delete should decrease counts and mark zip outdated.
    $galleryB = remove_photo($config, $galleryB, 'a1');
    t_assert(count($galleryB['files']) === 1, 'Photo delete should remove item');
    t_assert((int) ($galleryB['storage']['file_count'] ?? 0) === 1, 'File count cache should decrease after delete');
    t_assert(!empty($galleryB['zip']['dirty']), 'ZIP must be marked outdated after delete');

    // Old-gallery stats bootstrap: remove storage and force reload.
    $raw = read_json_file(gallery_json_path($config, (string) $galleryB['id']));
    unset($raw['storage']);
    write_json_file(gallery_json_path($config, (string) $galleryB['id']), $raw);
    $galleryB = load_gallery($config, (string) $galleryB['id']);
    t_assert(isset($galleryB['storage']['file_count']), 'Old gallery without cached stats should be initialized');

    echo "OK: all smoke checks passed\n";
} finally {
    rrmdir($testRoot);
}
