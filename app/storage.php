<?php
declare(strict_types=1);

function gallery_uuid_is_valid(string $uuid): bool
{
    return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid);
}

function configured_storage_roots(array $config): array
{
    $roots = [];
    foreach ((array) ($config['storage_disks'] ?? []) as $root) {
        $root = rtrim(trim((string) $root), '/');
        if ($root !== '' && !in_array($root, $roots, true)) {
            $roots[] = $root;
        }
    }
    $active = rtrim(trim((string) ($config['storage_root'] ?? '')), '/');
    if ($active !== '' && !in_array($active, $roots, true)) {
        $roots[] = $active;
    }
    return $roots;
}

function active_storage_root(array $config): string
{
    $root = rtrim(trim((string) ($config['storage_root'] ?? '')), '/');
    if ($root === '') {
        throw new RuntimeException('No active storage disk is configured.');
    }
    return $root;
}

function primary_storage_root(array $config): string
{
    $roots = configured_storage_roots($config);
    if ($roots) {
        return $roots[0];
    }
    return active_storage_root($config);
}

function ensure_storage_root_layout(string $root): void
{
    foreach ([$root, $root . '/_app', $root . '/_background', $root . '/_tmp'] as $dir) {
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create storage: ' . $dir);
        }
        if (!is_writable($dir)) {
            throw new RuntimeException('The web application does not have write permission to: ' . $dir);
        }
    }
}

function ensure_storage_layout(array $config): void
{
    ensure_storage_root_layout(active_storage_root($config));
    $primary = primary_storage_root($config);
    if ($primary !== active_storage_root($config)) {
        ensure_storage_root_layout($primary);
    }
}

function gallery_storage_locations(array $config, string $uuid): array
{
    if (!gallery_uuid_is_valid($uuid)) {
        throw new InvalidArgumentException('Invalid gallery UUID.');
    }
    $uuid = strtolower($uuid);
    $locations = [];
    foreach (configured_storage_roots($config) as $root) {
        $dir = $root . '/' . $uuid;
        $json = $dir . '/gallery.json';
        if (is_file($json)) {
            $locations[] = ['root' => $root, 'dir' => $dir, 'json' => $json];
        }
    }
    return $locations;
}

function find_gallery_storage(array $config, string $uuid): ?array
{
    $locations = gallery_storage_locations($config, $uuid);
    if (!$locations) {
        return null;
    }
    if (count($locations) > 1) {
        throw new RuntimeException('Duplicate gallery UUID detected on multiple storage disks. Resolve the conflict in admin settings before continuing.');
    }
    return $locations[0];
}

function gallery_dir(array $config, string $uuid, bool $forCreate = false): string
{
    if (!gallery_uuid_is_valid($uuid)) {
        throw new InvalidArgumentException('Invalid gallery UUID.');
    }
    if ($forCreate) {
        return active_storage_root($config) . '/' . strtolower($uuid);
    }
    $location = find_gallery_storage($config, $uuid);
    if ($location) {
        return $location['dir'];
    }
    return active_storage_root($config) . '/' . strtolower($uuid);
}

function gallery_json_path(array $config, string $uuid, bool $forCreate = false): string
{
    return gallery_dir($config, $uuid, $forCreate) . '/gallery.json';
}

function gallery_stats_path(array $config, string $uuid): string
{
    return gallery_dir($config, $uuid) . '/.stats.json';
}

function gallery_stats_lock_path(array $config, string $uuid): string
{
    return gallery_dir($config, $uuid) . '/.stats.lock';
}

function gallery_zip_lock_path(array $config, string $uuid): string
{
    return gallery_dir($config, $uuid) . '/.zip.lock';
}

function gallery_auth_version(array $gallery): int
{
    return max(1, (int) ($gallery['auth_version'] ?? 1));
}

function normalize_gallery_storage_stats(array $gallery): array
{
    $storage = is_array($gallery['storage'] ?? null) ? $gallery['storage'] : [];
    $storage['file_count'] = max(0, (int) ($storage['file_count'] ?? count($gallery['files'] ?? [])));
    $storage['original_bytes'] = max(0, (int) ($storage['original_bytes'] ?? 0));
    $storage['preview_bytes'] = max(0, (int) ($storage['preview_bytes'] ?? 0));
    $storage['zip_bytes'] = max(0, (int) ($storage['zip_bytes'] ?? 0));
    $storage['updated_at'] = !empty($storage['updated_at']) ? (string) $storage['updated_at'] : null;
    $gallery['storage'] = $storage;
    $gallery['_disk_bytes'] = $storage['original_bytes'] + $storage['preview_bytes'] + $storage['zip_bytes'];
    return $gallery;
}

function normalize_gallery_zip(array $gallery): array
{
    $zip = is_array($gallery['zip'] ?? null) ? $gallery['zip'] : [];
    $ready = !empty($zip['ready']);
    $zip['ready'] = $ready;
    $zip['dirty'] = array_key_exists('dirty', $zip) ? (bool) $zip['dirty'] : !$ready;
    $zip['updated_at'] = !empty($zip['updated_at']) ? (string) $zip['updated_at'] : null;
    $zip['size'] = max(0, (int) ($zip['size'] ?? 0));
    $gallery['zip'] = $zip;
    return $gallery;
}

function normalize_gallery_base(array $gallery): array
{
    $gallery['id'] = strtolower((string) ($gallery['id'] ?? ''));
    $gallery['active'] = !array_key_exists('active', $gallery) || !empty($gallery['active']);
    $gallery['files'] = is_array($gallery['files'] ?? null) ? array_values($gallery['files']) : [];
    $gallery['background'] = is_array($gallery['background'] ?? null) ? $gallery['background'] : ['mode' => 'global'];
    $gallery['auth_version'] = gallery_auth_version($gallery);
    $gallery = normalize_gallery_zip($gallery);
    return normalize_gallery_storage_stats($gallery);
}

function default_gallery_live_stats(array $gallery): array
{
    $legacyDownloads = max(0, (int) ($gallery['download_count'] ?? 0));
    $photoDownloads = max(0, (int) ($gallery['photo_downloads'] ?? 0));
    $zipDownloads = max(0, (int) ($gallery['zip_downloads'] ?? 0));
    if ($legacyDownloads > 0 && $photoDownloads === 0 && $zipDownloads === 0) {
        $photoDownloads = $legacyDownloads;
    }
    return [
        'views' => max(0, (int) ($gallery['views'] ?? 0)),
        'unlocks' => max(0, (int) ($gallery['unlocks'] ?? 0)),
        'photo_downloads' => $photoDownloads,
        'zip_downloads' => $zipDownloads,
        'last_access_at' => !empty($gallery['last_access_at']) ? (string) $gallery['last_access_at'] : null,
    ];
}

function normalize_live_stats(array $stats): array
{
    $stats['views'] = max(0, (int) ($stats['views'] ?? 0));
    $stats['unlocks'] = max(0, (int) ($stats['unlocks'] ?? 0));
    $stats['photo_downloads'] = max(0, (int) ($stats['photo_downloads'] ?? 0));
    $stats['zip_downloads'] = max(0, (int) ($stats['zip_downloads'] ?? 0));
    $stats['last_access_at'] = !empty($stats['last_access_at']) ? (string) $stats['last_access_at'] : null;
    return $stats;
}

function merge_gallery_live_stats(array $gallery, array $stats): array
{
    $stats = normalize_live_stats($stats);
    $gallery['views'] = $stats['views'];
    $gallery['unlocks'] = $stats['unlocks'];
    $gallery['photo_downloads'] = $stats['photo_downloads'];
    $gallery['zip_downloads'] = $stats['zip_downloads'];
    $gallery['download_count'] = $stats['photo_downloads'] + $stats['zip_downloads'];
    $gallery['last_access_at'] = $stats['last_access_at'];
    return $gallery;
}

function read_json_file(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }
    $fp = fopen($path, 'rb');
    if ($fp === false) {
        return null;
    }
    try {
        flock($fp, LOCK_SH);
        $json = stream_get_contents($fp);
        flock($fp, LOCK_UN);
    } finally {
        fclose($fp);
    }
    $data = json_decode((string) $json, true);
    return is_array($data) ? $data : null;
}

function write_json_file(string $path, array $data): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0770, true);
    }
    $tmp = $path . '.tmp-' . bin2hex(random_bytes(6));
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (file_put_contents($tmp, $json . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('Writing JSON failed.');
    }
    chmod($tmp, 0660);
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('Replacing JSON failed.');
    }
}

function refresh_gallery_storage_stats(array $config, array $gallery, bool $persist = true): array
{
    $dir = gallery_dir($config, (string) $gallery['id']);
    $fileCount = 0;
    $originalBytes = 0;
    $previewBytes = 0;

    foreach ($gallery['files'] ?? [] as $file) {
        $fileCount++;
        $original = $dir . '/originals/' . basename((string) ($file['stored_name'] ?? ''));
        if (is_file($original)) {
            $originalBytes += (int) (filesize($original) ?: 0);
        }
        $previewName = (string) ($file['preview'] ?? '');
        if ($previewName !== '') {
            $preview = $dir . '/previews/' . basename($previewName);
            if (is_file($preview)) {
                $previewBytes += (int) (filesize($preview) ?: 0);
            }
        }
    }

    $zipFile = $dir . '/full.zip';
    $zipBytes = is_file($zipFile) ? (int) (filesize($zipFile) ?: 0) : 0;
    $gallery['storage'] = [
        'file_count' => $fileCount,
        'original_bytes' => $originalBytes,
        'preview_bytes' => $previewBytes,
        'zip_bytes' => $zipBytes,
        'updated_at' => date(DATE_ATOM),
    ];
    $gallery = normalize_gallery_storage_stats($gallery);

    if ($persist) {
        save_gallery($config, $gallery);
    }
    return $gallery;
}

function ensure_gallery_storage_stats(array $config, array $gallery, bool $persistIfMissing = true): array
{
    $gallery = normalize_gallery_storage_stats($gallery);
    $storage = $gallery['storage'] ?? [];
    $hasAll = isset($storage['file_count'], $storage['original_bytes'], $storage['preview_bytes'], $storage['zip_bytes']);
    if ($hasAll && $storage['updated_at'] !== null) {
        return $gallery;
    }
    return refresh_gallery_storage_stats($config, $gallery, $persistIfMissing);
}

function load_gallery_live_stats(array $config, array $gallery): array
{
    $default = default_gallery_live_stats($gallery);
    $path = gallery_stats_path($config, (string) $gallery['id']);
    $stored = read_json_file($path);
    if (!$stored) {
        return normalize_live_stats($default);
    }
    return normalize_live_stats(array_merge($default, $stored));
}

function save_gallery_live_stats(array $config, string $uuid, array $stats): void
{
    write_json_file(gallery_stats_path($config, $uuid), normalize_live_stats($stats));
}

function gallery_record_event(array $config, array $gallery, string $event): array
{
    $uuid = (string) $gallery['id'];
    $lockPath = gallery_stats_lock_path($config, $uuid);
    $lock = fopen($lockPath, 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        if (is_resource($lock)) {
            fclose($lock);
        }
        throw new RuntimeException('Gallery statistics are temporarily locked.');
    }
    @chmod($lockPath, 0660);

    try {
        $stats = load_gallery_live_stats($config, $gallery);
        if ($event === 'view') {
            $stats['views']++;
        } elseif ($event === 'unlock') {
            $stats['unlocks']++;
        } elseif ($event === 'photo_download') {
            $stats['photo_downloads']++;
        } elseif ($event === 'zip_download') {
            $stats['zip_downloads']++;
        }
        $stats['last_access_at'] = date(DATE_ATOM);
        save_gallery_live_stats($config, $uuid, $stats);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    return merge_gallery_live_stats($gallery, $stats);
}

function list_galleries(array $config): array
{
    $itemsByUuid = [];
    $duplicates = [];

    foreach (configured_storage_roots($config) as $root) {
        foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $uuid = basename($dir);
            if ($uuid === '' || $uuid[0] === '_' || !gallery_uuid_is_valid($uuid)) {
                continue;
            }

            if (isset($itemsByUuid[$uuid])) {
                $duplicates[$uuid][] = $root;
                continue;
            }

            try {
                $gallery = read_json_file($dir . '/gallery.json');
                if (!$gallery) {
                    continue;
                }
                $gallery = normalize_gallery_base($gallery);
                $gallery = merge_gallery_live_stats($gallery, load_gallery_live_stats($config, $gallery));
                $gallery = ensure_gallery_storage_stats($config, $gallery, true);
                $gallery['_storage_root'] = $root;
                $gallery['_storage_label'] = basename($root) !== '' ? basename($root) : $root;
                $itemsByUuid[$uuid] = $gallery;
            } catch (Throwable) {
                // Skip broken entries in list; detail route can expose actionable errors.
            }
        }
    }

    foreach ($duplicates as $uuid => $duplicateRoots) {
        if (!isset($itemsByUuid[$uuid])) {
            continue;
        }
        $itemsByUuid[$uuid]['_disk_conflict'] = true;
        $itemsByUuid[$uuid]['_duplicate_roots'] = array_values(array_unique($duplicateRoots));
    }

    $items = array_values($itemsByUuid);
    usort($items, fn(array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
    return $items;
}

function create_gallery(array $config, string $plainPassword, string $name = ''): array
{
    ensure_storage_layout($config);
    $uuid = '';
    for ($i = 0; $i < 16; $i++) {
        $candidate = uuid_v4();
        if (!gallery_storage_locations($config, $candidate)) {
            $uuid = $candidate;
            break;
        }
    }
    if ($uuid === '') {
        throw new RuntimeException('Could not generate a unique gallery UUID.');
    }

    $dir = gallery_dir($config, $uuid, true);
    foreach ([$dir, $dir . '/originals', $dir . '/previews'] as $path) {
        if (!mkdir($path, 0770, true) && !is_dir($path)) {
            throw new RuntimeException('Could not create the gallery.');
        }
    }

    $gallery = [
        'id' => $uuid,
        'name' => normalize_gallery_name($name),
        'password_hash' => secure_password_hash($plainPassword),
        'password_admin' => encrypt_admin_value($config, $plainPassword),
        'created_at' => date(DATE_ATOM),
        'updated_at' => date(DATE_ATOM),
        'active' => true,
        'auth_version' => 1,
        'background' => ['mode' => 'global'],
        'files' => [],
        'zip' => ['ready' => false, 'dirty' => true, 'updated_at' => null, 'size' => 0],
        'storage' => [
            'file_count' => 0,
            'original_bytes' => 0,
            'preview_bytes' => 0,
            'zip_bytes' => 0,
            'updated_at' => date(DATE_ATOM),
        ],
    ];

    write_json_file(gallery_json_path($config, $uuid, true), $gallery);
    save_gallery_live_stats($config, $uuid, default_gallery_live_stats($gallery));
    return merge_gallery_live_stats(normalize_gallery_base($gallery), default_gallery_live_stats($gallery));
}

function normalize_gallery_name(string $name): string
{
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
    return app_substr($name, 0, 120);
}

function rename_gallery(array $config, array $gallery, string $name): array
{
    $gallery['name'] = normalize_gallery_name($name);
    save_gallery($config, $gallery);
    return $gallery;
}

function load_gallery(array $config, string $uuid): ?array
{
    if (!gallery_uuid_is_valid($uuid)) {
        return null;
    }
    $location = find_gallery_storage($config, $uuid);
    if (!$location) {
        return null;
    }
    $gallery = read_json_file($location['json']);
    if (!$gallery) {
        return null;
    }
    $gallery = normalize_gallery_base($gallery);
    $gallery = merge_gallery_live_stats($gallery, load_gallery_live_stats($config, $gallery));
    $gallery = ensure_gallery_storage_stats($config, $gallery, true);
    $gallery['_storage_root'] = $location['root'];
    $gallery['_storage_label'] = basename($location['root']) !== '' ? basename($location['root']) : $location['root'];
    return $gallery;
}

function save_gallery(array $config, array $gallery): void
{
    $gallery = normalize_gallery_base($gallery);
    $gallery['updated_at'] = date(DATE_ATOM);
    write_json_file(gallery_json_path($config, (string) $gallery['id']), $gallery);
}

function validate_photo_upload(array $config, array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed (code ' . (int) ($file['error'] ?? -1) . ').');
    }
    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > (int) $config['max_photo_bytes']) {
        throw new RuntimeException('The file exceeds the 200 MB limit or is empty.');
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    if (!is_uploaded_file($tmp)) {
        throw new RuntimeException('Invalid temporary upload.');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($tmp);
    $imageFormats = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    if (isset($imageFormats[$mime]) && @getimagesize($tmp) !== false) {
        return ['mime' => $mime, 'extension' => $imageFormats[$mime], 'size' => $size, 'previewable' => true];
    }

    $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    $rawFormats = [
        'dng' => 'image/x-adobe-dng',
        'cr2' => 'image/x-canon-cr2',
        'cr3' => 'image/x-canon-cr3',
        'nef' => 'image/x-nikon-nef',
        'arw' => 'image/x-sony-arw',
        'raf' => 'image/x-fuji-raf',
        'orf' => 'image/x-olympus-orf',
        'rw2' => 'image/x-panasonic-rw2',
        'pef' => 'image/x-pentax-pef',
        '3fr' => 'image/x-hasselblad-3fr',
        'iiq' => 'image/x-phaseone-iiq',
        'x3f' => 'image/x-sigma-x3f',
    ];
    if (isset($rawFormats[$extension])) {
        return ['mime' => $rawFormats[$extension], 'extension' => $extension, 'size' => $size, 'previewable' => false];
    }

    throw new RuntimeException('Only valid JPG, PNG, WebP, and supported RAW photos are allowed.');
}

function mark_gallery_zip_dirty(array $config, array $gallery): array
{
    $dir = gallery_dir($config, (string) $gallery['id']);
    @unlink($dir . '/full.zip');
    $gallery['zip'] = [
        'ready' => false,
        'dirty' => true,
        'updated_at' => null,
        'size' => 0,
    ];
    $gallery = normalize_gallery_storage_stats($gallery);
    $gallery['storage']['zip_bytes'] = 0;
    $gallery['storage']['updated_at'] = date(DATE_ATOM);
    $gallery['_disk_bytes'] = $gallery['storage']['original_bytes'] + $gallery['storage']['preview_bytes'];
    return $gallery;
}

function add_uploaded_photos(array $config, array $gallery, array $files): array
{
    $normalized = normalize_files_array($files);
    $dir = gallery_dir($config, (string) $gallery['id']);
    $gallery = normalize_gallery_storage_stats($gallery);

    foreach ($normalized as $file) {
        $meta = validate_photo_upload($config, $file);
        $fileId = bin2hex(random_bytes(12));
        $stored = $fileId . '.' . $meta['extension'];
        $target = $dir . '/originals/' . $stored;
        if (!move_uploaded_file((string) $file['tmp_name'], $target)) {
            throw new RuntimeException('The photo could not be saved.');
        }
        chmod($target, 0660);

        $previewPath = $dir . '/previews/' . $fileId . '.jpg';
        $previewReady = !empty($meta['previewable']) && create_preview($target, $previewPath, $meta['mime']);
        $previewName = $previewReady ? $fileId . '.jpg' : null;
        $previewSize = ($previewReady && is_file($previewPath)) ? (int) (filesize($previewPath) ?: 0) : 0;

        $gallery['files'][] = [
            'id' => $fileId,
            'original_name' => normalize_filename((string) ($file['name'] ?? 'photo.' . $meta['extension'])),
            'stored_name' => $stored,
            'mime' => $meta['mime'],
            'size' => $meta['size'],
            'preview' => $previewName,
            'uploaded_at' => date(DATE_ATOM),
        ];

        $gallery['storage']['file_count'] = max(0, (int) $gallery['storage']['file_count']) + 1;
        $gallery['storage']['original_bytes'] = max(0, (int) $gallery['storage']['original_bytes']) + $meta['size'];
        $gallery['storage']['preview_bytes'] = max(0, (int) $gallery['storage']['preview_bytes']) + $previewSize;
    }

    $gallery = mark_gallery_zip_dirty($config, $gallery);
    $gallery['storage']['updated_at'] = date(DATE_ATOM);
    save_gallery($config, $gallery);
    return $gallery;
}

function normalize_files_array(array $files): array
{
    if (!isset($files['name'])) {
        return [];
    }
    if (!is_array($files['name'])) {
        return [$files];
    }
    $out = [];
    foreach ($files['name'] as $i => $name) {
        $out[] = [
            'name' => $name,
            'type' => $files['type'][$i] ?? '',
            'tmp_name' => $files['tmp_name'][$i] ?? '',
            'error' => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE,
            'size' => $files['size'][$i] ?? 0,
        ];
    }
    return $out;
}

function create_preview(string $source, string $target, string $mime): bool
{
    if (!extension_loaded('gd')) {
        return false;
    }
    $info = @getimagesize($source);
    if (!$info || $info[0] < 1 || $info[1] < 1) {
        return false;
    }
    $create = match ($mime) {
        'image/jpeg' => 'imagecreatefromjpeg',
        'image/png' => 'imagecreatefrompng',
        'image/webp' => 'imagecreatefromwebp',
        default => null,
    };
    if (!$create || !function_exists($create)) {
        return false;
    }
    $src = @$create($source);
    if (!$src) {
        return false;
    }
    $max = 1200;
    $scale = min(1, $max / max($info[0], $info[1]));
    $w = max(1, (int) round($info[0] * $scale));
    $h = max(1, (int) round($info[1] * $scale));
    $dst = imagecreatetruecolor($w, $h);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, $info[0], $info[1]);
    $ok = imagejpeg($dst, $target, 84);
    imagedestroy($src);
    imagedestroy($dst);
    if ($ok) {
        chmod($target, 0660);
    }
    return $ok;
}

function remove_photo(array $config, array $gallery, string $fileId): array
{
    $dir = gallery_dir($config, (string) $gallery['id']);
    $gallery = normalize_gallery_storage_stats($gallery);
    $remaining = [];
    $found = false;

    foreach ($gallery['files'] ?? [] as $file) {
        if (($file['id'] ?? '') === $fileId) {
            $found = true;
            $originalPath = $dir . '/originals/' . basename((string) ($file['stored_name'] ?? ''));
            $previewPath = !empty($file['preview']) ? $dir . '/previews/' . basename((string) $file['preview']) : null;

            $originalSize = is_file($originalPath) ? (int) (filesize($originalPath) ?: 0) : (int) ($file['size'] ?? 0);
            $previewSize = ($previewPath && is_file($previewPath)) ? (int) (filesize($previewPath) ?: 0) : 0;

            @unlink($originalPath);
            if ($previewPath) {
                @unlink($previewPath);
            }

            $gallery['storage']['file_count'] = max(0, (int) $gallery['storage']['file_count'] - 1);
            $gallery['storage']['original_bytes'] = max(0, (int) $gallery['storage']['original_bytes'] - $originalSize);
            $gallery['storage']['preview_bytes'] = max(0, (int) $gallery['storage']['preview_bytes'] - $previewSize);
            continue;
        }
        $remaining[] = $file;
    }

    if (!$found) {
        throw new RuntimeException('The photo was not found.');
    }

    $gallery['files'] = $remaining;
    $gallery = mark_gallery_zip_dirty($config, $gallery);
    $gallery['storage']['updated_at'] = date(DATE_ATOM);
    save_gallery($config, $gallery);
    return $gallery;
}

function gallery_zip_is_current(array $config, array $gallery): bool
{
    $gallery = normalize_gallery_zip($gallery);
    if (empty($gallery['zip']['ready']) || !empty($gallery['zip']['dirty'])) {
        return false;
    }
    $target = gallery_dir($config, (string) $gallery['id']) . '/full.zip';
    if (!is_file($target)) {
        return false;
    }
    $size = (int) (filesize($target) ?: 0);
    return $size > 0 && $size === (int) ($gallery['zip']['size'] ?? 0);
}

function build_gallery_zip(array $config, array $gallery, bool $force = true): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('The server is missing the PHP ZIP extension (ZipArchive).');
    }

    $uuid = (string) $gallery['id'];
    $dir = gallery_dir($config, $uuid);
    $lockPath = gallery_zip_lock_path($config, $uuid);
    $lock = fopen($lockPath, 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        if (is_resource($lock)) {
            fclose($lock);
        }
        throw new RuntimeException('ZIP generation is already running.');
    }
    @chmod($lockPath, 0660);

    try {
        $fresh = load_gallery($config, $uuid);
        if (!$fresh) {
            throw new RuntimeException('Gallery not found.');
        }
        if (!$force && gallery_zip_is_current($config, $fresh)) {
            return $fresh;
        }

        $tmp = $dir . '/full.zip.tmp-' . bin2hex(random_bytes(6));
        $target = $dir . '/full.zip';
        @unlink($tmp);

        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The ZIP could not be created.');
        }

        $usedNames = [];
        foreach ($fresh['files'] ?? [] as $file) {
            $source = $dir . '/originals/' . basename((string) ($file['stored_name'] ?? ''));
            if (!is_file($source)) {
                continue;
            }
            $name = unique_zip_name((string) ($file['original_name'] ?? 'photo'), $usedNames);
            if (!$zip->addFile($source, $name)) {
                $zip->close();
                @unlink($tmp);
                throw new RuntimeException('A photo could not be added to the ZIP archive.');
            }
        }

        $zip->close();
        if (!rename($tmp, $target)) {
            @unlink($tmp);
            throw new RuntimeException('The completed ZIP could not be moved.');
        }
        chmod($target, 0660);

        $fresh['zip'] = [
            'ready' => true,
            'dirty' => false,
            'updated_at' => date(DATE_ATOM),
            'size' => (int) (filesize($target) ?: 0),
        ];
        $fresh = normalize_gallery_storage_stats($fresh);
        $fresh['storage']['zip_bytes'] = (int) ($fresh['zip']['size'] ?? 0);
        $fresh['storage']['updated_at'] = date(DATE_ATOM);
        save_gallery($config, $fresh);
        return $fresh;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function ensure_gallery_zip_ready(array $config, array $gallery): array
{
    if (gallery_zip_is_current($config, $gallery)) {
        return $gallery;
    }
    return build_gallery_zip($config, $gallery, true);
}

function unique_zip_name(string $name, array &$used): string
{
    $name = normalize_filename($name);
    $base = pathinfo($name, PATHINFO_FILENAME);
    $ext = pathinfo($name, PATHINFO_EXTENSION);
    $candidate = $name;
    $i = 2;
    while (isset($used[app_strtolower($candidate)])) {
        $candidate = $base . '-' . $i . ($ext !== '' ? '.' . $ext : '');
        $i++;
    }
    $used[app_strtolower($candidate)] = true;
    return $candidate;
}

function delete_gallery(array $config, string $uuid): void
{
    $location = find_gallery_storage($config, $uuid);
    if (!$location || !is_dir($location['dir'])) {
        return;
    }
    $dir = $location['dir'];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}

function current_background(array $config): ?array
{
    return read_json_file(active_storage_root($config) . '/_background/background.json');
}

function effective_background(array $config, ?array $gallery = null): ?array
{
    if (is_array($gallery)) {
        $galleryBackground = $gallery['background'] ?? null;
        if (is_array($galleryBackground)
            && ($galleryBackground['mode'] ?? '') === 'custom'
            && ($galleryBackground['source'] ?? '') === 'url'
            && !empty($galleryBackground['url'])) {
            return $galleryBackground;
        }
    }
    return current_background($config);
}

function save_gallery_background(array $config, array $gallery, string $mode, string $url = ''): array
{
    if ($mode === 'global') {
        $gallery['background'] = [
            'mode' => 'global',
            'updated_at' => date(DATE_ATOM),
        ];
    } elseif ($mode === 'custom') {
        $gallery['background'] = [
            'mode' => 'custom',
            'source' => 'url',
            'url' => validate_background_url($url),
            'type' => 'image',
            'updated_at' => date(DATE_ATOM),
        ];
    } else {
        throw new RuntimeException('Invalid gallery background mode.');
    }
    save_gallery($config, $gallery);
    return $gallery;
}

function save_background_upload(array $config, array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Background upload failed.');
    }
    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > (int) $config['max_background_bytes']) {
        throw new RuntimeException('The background is empty or exceeds 250 MB.');
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    if (!is_uploaded_file($tmp)) {
        throw new RuntimeException('Invalid background upload.');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($tmp);
    $allowed = [
        'image/gif' => ['gif', 'image'],
        'image/jpeg' => ['jpg', 'image'],
        'image/png' => ['png', 'image'],
        'image/webp' => ['webp', 'image'],
        'video/mp4' => ['mp4', 'video'],
        'video/webm' => ['webm', 'video'],
    ];
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('The background must be GIF, JPG, PNG, WebP, MP4, or WebM.');
    }
    [$ext, $type] = $allowed[$mime];
    $dir = active_storage_root($config) . '/_background';
    foreach (glob($dir . '/current.*') ?: [] as $old) {
        @unlink($old);
    }
    $name = 'current.' . $ext;
    if (!move_uploaded_file($tmp, $dir . '/' . $name)) {
        throw new RuntimeException('The background could not be saved.');
    }
    chmod($dir . '/' . $name, 0660);
    $data = ['file' => $name, 'mime' => $mime, 'type' => $type, 'size' => $size, 'updated_at' => date(DATE_ATOM)];
    write_json_file($dir . '/background.json', $data);
    return $data;
}

function validate_background_url(string $url): string
{
    $url = trim($url);
    if ($url === '' || strlen($url) > 2048) {
        throw new RuntimeException('Please enter a valid background URL.');
    }
    $parts = parse_url($url);
    if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) {
        throw new RuntimeException('The background URL must start with https://');
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
        throw new RuntimeException('The background URL must not contain credentials.');
    }
    return $url;
}

function save_background_url(array $config, string $url): array
{
    $url = validate_background_url($url);
    $dir = active_storage_root($config) . '/_background';
    if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new RuntimeException('The background folder could not be created.');
    }
    $data = [
        'source' => 'url',
        'url' => $url,
        'type' => 'image',
        'updated_at' => date(DATE_ATOM),
    ];
    write_json_file($dir . '/background.json', $data);
    return $data;
}
