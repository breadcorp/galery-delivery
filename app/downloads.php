<?php
declare(strict_types=1);

/** Single byte range; malformed/multiple ranges are ignored per HTTP semantics. */
function download_range(int $size, string $header): array
{
    $full = ['status' => 200, 'start' => 0, 'length' => $size];
    if ($header === '' || !preg_match('/^bytes=(\d*)-(\d*)$/D', trim($header), $m)) return $full;
    if ($size === 0 || ($m[1] === '' && ($m[2] === '' || (int) $m[2] <= 0))) return ['status' => 416, 'start' => 0, 'length' => 0];
    if ($m[1] === '') {
        $start = max(0, $size - (int) $m[2]);
        $end = $size - 1;
    } else {
        $start = (int) $m[1];
        $end = $m[2] === '' ? $size - 1 : min((int) $m[2], $size - 1);
    }
    if ($start >= $size || $start > $end) return ['status' => 416, 'start' => 0, 'length' => 0];
    return ['status' => 206, 'start' => $start, 'length' => $end - $start + 1];
}

function download_offload_header(array $config, string $path): ?array
{
    $backend = (string) ($config['download_backend'] ?? 'php');
    if ($backend === 'php') return null;
    $real = realpath($path);
    if ($real === false) throw new RuntimeException('Download file not found.');
    $real = str_replace('\\', '/', $real);
    $allowed = false;
    foreach (configured_storage_roots($config) as $root) {
        $resolved = realpath($root);
        if ($resolved !== false && str_starts_with($real, rtrim(str_replace('\\', '/', $resolved), '/') . '/')) $allowed = true;
    }
    if (!$allowed) throw new RuntimeException('Download is outside configured storage.');
    if ($backend === 'x-sendfile') return ['X-Sendfile', $real];
    if ($backend !== 'x-accel') throw new RuntimeException('Unknown download backend.');
    foreach ((array) ($config['x_accel_mappings'] ?? []) as $root => $uri) {
        $resolved = realpath((string) $root);
        if ($resolved === false) continue;
        $prefix = rtrim(str_replace('\\', '/', $resolved), '/') . '/';
        if (!str_starts_with($real, $prefix)) continue;
        if (!preg_match('#^/[a-zA-Z0-9/_-]+/$#D', (string) $uri)) throw new RuntimeException('Invalid internal download URI.');
        $relative = implode('/', array_map('rawurlencode', explode('/', substr($real, strlen($prefix)))));
        return ['X-Accel-Redirect', $uri . $relative];
    }
    throw new RuntimeException('No internal download mapping for this storage disk.');
}

function send_file_response(string $path, string $mime, string $disposition, string $downloadName,
    bool $allowRanges = true, bool $deleteAfterSend = false, ?callable $onStart = null): void
{
    $fp = @fopen($path, 'rb');
    if ($fp === false) { http_response_code(404); exit; }
    $stat = fstat($fp);
    $size = (int) $stat['size'];
    $etag = '"' . hash('sha256', $path . '|' . $stat['ino'] . '|' . $size . '|' . $stat['mtime'] . '|' . $stat['ctime']) . '"';
    $modified = gmdate('D, d M Y H:i:s', (int) $stat['mtime']) . ' GMT';
    $head = request_method() === 'HEAD';
    $rangeHeader = $allowRanges && !$head ? (string) ($_SERVER['HTTP_RANGE'] ?? '') : '';
    $requestedRange = download_range($size, $rangeHeader);
    $ifRange = (string) ($_SERVER['HTTP_IF_RANGE'] ?? '');
    if ($ifRange !== '' && $ifRange !== $etag && $ifRange !== $modified) $rangeHeader = '';
    $range = download_range($size, $rangeHeader);
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header('Accept-Ranges: ' . ($allowRanges ? 'bytes' : 'none'));
    if ($range['status'] === 416) {
        fclose($fp);
        if ($deleteAfterSend) @unlink($path);
        http_response_code(416);
        header('Content-Range: bytes */' . $size);
        header('Content-Length: 0');
        exit;
    }
    header('Content-Type: ' . $mime);
    header('Content-Disposition: ' . $disposition . '; filename="' . safe_download_name($downloadName) . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
    $offload = !$deleteAfterSend ? download_offload_header($GLOBALS['config'] ?? [], $path) : null;
    // A resumed range is not a second download. Counts represent starts, not completed transfers.
    if (!$head && $requestedRange['status'] !== 416 && $requestedRange['start'] === 0 && $onStart !== null) $onStart();
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    if ($offload !== null) {
        fclose($fp);
        header($offload[0] . ': ' . $offload[1]);
        exit; // Web server supplies Range, length, validators, and the body.
    }
    header('ETag: ' . $etag);
    header('Last-Modified: ' . $modified);
    http_response_code($range['status']);
    header('Content-Length: ' . $range['length']);
    if ($range['status'] === 206) header('Content-Range: bytes ' . $range['start'] . '-' . ($range['start'] + $range['length'] - 1) . '/' . $size);
    try {
        if (!$head) {
            @set_time_limit(0);
            fseek($fp, $range['start']);
            $remaining = $range['length'];
            while ($remaining > 0 && !feof($fp) && !connection_aborted()) {
                $buffer = fread($fp, min(131072, $remaining));
                if ($buffer === false || $buffer === '') break;
                echo $buffer;
                $remaining -= strlen($buffer);
            }
        }
    } finally {
        fclose($fp);
        if ($deleteAfterSend) @unlink($path);
    }
    exit;
}
