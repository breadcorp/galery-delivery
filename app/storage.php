<?php
declare(strict_types=1);

function ensure_storage_layout(array $config): void
{
    $root = (string) $config['storage_root'];
    foreach ([$root, $root . '/_app', $root . '/_background', $root . '/_tmp'] as $dir) {
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create storage: ' . $dir);
        }
        if (!is_writable($dir)) {
            throw new RuntimeException('The web application does not have write permission to: ' . $dir);
        }
    }
}

function gallery_dir(array $config, string $uuid): string
{
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid)) {
        throw new InvalidArgumentException('Invalid gallery UUID.');
    }
    return rtrim((string) $config['storage_root'], '/') . '/' . strtolower($uuid);
}

function gallery_json_path(array $config, string $uuid): string
{
    return gallery_dir($config, $uuid) . '/gallery.json';
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

function list_galleries(array $config): array
{
    $root = rtrim((string) $config['storage_root'], '/');
    $items = [];
    foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $uuid = basename($dir);
        if ($uuid[0] === '_') {
            continue;
        }
        try {
            $gallery = read_json_file($dir . '/gallery.json');
            if ($gallery) {
                $gallery['_disk_bytes'] = directory_size($dir);
                $items[] = $gallery;
            }
        } catch (Throwable) {
            // Skip broken entries in the list; detail route will expose the issue.
        }
    }
    usort($items, fn(array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
    return $items;
}

function directory_size(string $dir): int
{
    $size = 0;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        if ($file->isFile()) {
            $size += $file->getSize();
        }
    }
    return $size;
}

function create_gallery(array $config, string $plainPassword, string $name = ''): array
{
    $uuid = uuid_v4();
    $dir = gallery_dir($config, $uuid);
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
        'download_count' => 0,
        'background' => ['mode' => 'global'],
        'files' => [],
        'zip' => ['ready' => false, 'updated_at' => null, 'size' => 0],
    ];
    write_json_file(gallery_json_path($config, $uuid), $gallery);
    return $gallery;
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
    return read_json_file(gallery_json_path($config, $uuid));
}

function save_gallery(array $config, array $gallery): void
{
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
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    if (!isset($allowed[$mime]) || @getimagesize($tmp) === false) {
        throw new RuntimeException('Only valid JPG, PNG, and WebP photos are allowed.');
    }
    return ['mime' => $mime, 'extension' => $allowed[$mime], 'size' => $size];
}

function add_uploaded_photos(array $config, array $gallery, array $files): array
{
    $normalized = normalize_files_array($files);
    $dir = gallery_dir($config, (string) $gallery['id']);
    foreach ($normalized as $file) {
        $meta = validate_photo_upload($config, $file);
        $fileId = bin2hex(random_bytes(12));
        $stored = $fileId . '.' . $meta['extension'];
        $target = $dir . '/originals/' . $stored;
        if (!move_uploaded_file((string) $file['tmp_name'], $target)) {
            throw new RuntimeException('The photo could not be saved.');
        }
        chmod($target, 0660);
        $previewReady = create_preview($target, $dir . '/previews/' . $fileId . '.jpg', $meta['mime']);
        $gallery['files'][] = [
            'id' => $fileId,
            'original_name' => normalize_filename((string) ($file['name'] ?? 'photo.' . $meta['extension'])),
            'stored_name' => $stored,
            'mime' => $meta['mime'],
            'size' => $meta['size'],
            'preview' => $previewReady ? $fileId . '.jpg' : null,
            'uploaded_at' => date(DATE_ATOM),
        ];
    }
    $gallery['zip'] = ['ready' => false, 'updated_at' => null, 'size' => 0];
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
    $remaining = [];
    $found = false;
    foreach ($gallery['files'] ?? [] as $file) {
        if (($file['id'] ?? '') === $fileId) {
            $found = true;
            @unlink($dir . '/originals/' . basename((string) $file['stored_name']));
            if (!empty($file['preview'])) {
                @unlink($dir . '/previews/' . basename((string) $file['preview']));
            }
            continue;
        }
        $remaining[] = $file;
    }
    if (!$found) {
        throw new RuntimeException('The photo was not found.');
    }
    $gallery['files'] = $remaining;
    @unlink($dir . '/full.zip');
    $gallery['zip'] = ['ready' => false, 'updated_at' => null, 'size' => 0];
    save_gallery($config, $gallery);
    return $gallery;
}

function build_gallery_zip(array $config, array $gallery): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('The server is missing the PHP ZIP extension (ZipArchive).');
    }
    $dir = gallery_dir($config, (string) $gallery['id']);
    $tmp = $dir . '/full.zip.tmp';
    $target = $dir . '/full.zip';
    @unlink($tmp);
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('The ZIP could not be created.');
    }
    $usedNames = [];
    foreach ($gallery['files'] ?? [] as $file) {
        $source = $dir . '/originals/' . basename((string) $file['stored_name']);
        if (!is_file($source)) {
            continue;
        }
        $name = unique_zip_name((string) $file['original_name'], $usedNames);
        $zip->addFile($source, $name);
    }
    $zip->close();
    if (!rename($tmp, $target)) {
        @unlink($tmp);
        throw new RuntimeException('The completed ZIP could not be moved.');
    }
    chmod($target, 0660);
    $gallery['zip'] = [
        'ready' => true,
        'updated_at' => date(DATE_ATOM),
        'size' => filesize($target) ?: 0,
    ];
    save_gallery($config, $gallery);
    return $gallery;
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
    $dir = gallery_dir($config, $uuid);
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

function current_background(array $config): ?array
{
    return read_json_file(rtrim((string) $config['storage_root'], '/') . '/_background/background.json');
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
    $dir = rtrim((string) $config['storage_root'], '/') . '/_background';
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
    $dir = rtrim((string) $config['storage_root'], '/') . '/_background';
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
