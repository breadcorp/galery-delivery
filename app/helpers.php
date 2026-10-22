<?php
declare(strict_types=1);

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function base_url(array $config, string $path = ''): string
{
    return rtrim((string) $config['base_path'], '/') . '/' . ltrim($path, '/');
}

function redirect_to(array $config, string $path): never
{
    header('Location: ' . base_url($config, $path), true, 302);
    exit;
}

function absolute_url(array $config, string $path = ''): string
{
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    return 'https://' . $host . base_url($config, $path);
}

function request_method(): string
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

function route_path(array $config): string
{
    $uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $base = rtrim((string) $config['base_path'], '/');
    if ($base !== '' && str_starts_with($uriPath, $base)) {
        $uriPath = substr($uriPath, strlen($base));
    }
    return '/' . trim(rawurldecode($uriPath), '/');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['csrf'];
}

function verify_csrf(): void
{
    $provided = $_POST['csrf'] ?? '';
    if (!is_string($provided) || !hash_equals(csrf_token(), $provided)) {
        http_response_code(419);
        exit('Invalid or expired form. Please refresh the page.');
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function consume_flashes(): array
{
    $items = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return is_array($items) ? $items : [];
}

function is_admin(): bool
{
    return !empty($_SESSION['admin_authenticated']);
}

function is_primary_admin(): bool
{
    return is_admin() && (string) ($_SESSION['admin_username'] ?? '') === 'admin';
}

function admin_roles(): array
{
    return [
        'full' => 'Full access',
        'edit' => 'Edit without delete',
        'download' => 'Download only',
    ];
}

function normalize_admin_role(string $role): string
{
    return array_key_exists($role, admin_roles()) ? $role : 'download';
}

function current_admin_role(): string
{
    return is_primary_admin() ? 'full' : normalize_admin_role((string) ($_SESSION['admin_role'] ?? 'download'));
}

function admin_can(string $permission): bool
{
    $role = current_admin_role();
    return match ($permission) {
        'download', 'view' => is_admin(),
        'edit' => $role === 'full' || $role === 'edit',
        'delete', 'settings' => $role === 'full',
        default => false,
    };
}

function require_admin(array $config): void
{
    if (!is_admin()) {
        redirect_to($config, 'admin');
    }
}

function require_primary_admin(array $config): void
{
    require_admin($config);
    if (!is_primary_admin()) {
        flash('error', 'Only the admin account can manage administrator accounts.');
        redirect_to($config, 'admin/settings');
    }
}

function require_admin_permission(array $config, string $permission): void
{
    require_admin($config);
    if (!admin_can($permission)) {
        flash('error', 'Your account does not have permission for this action.');
        redirect_to($config, 'admin');
    }
}

function normalize_admin_username(string $username): string
{
    return app_strtolower(trim($username));
}

function is_valid_admin_username(string $username): bool
{
    return preg_match('/\A[a-z0-9][a-z0-9._-]{2,31}\z/', $username) === 1;
}

function admin_users(array $config): array
{
    $users = [];
    foreach ((array) ($config['admin_users'] ?? []) as $username => $user) {
        $username = normalize_admin_username((string) $username);
        $passwordHash = is_array($user) ? (string) ($user['password_hash'] ?? '') : (string) $user;
        if (is_valid_admin_username($username) && $passwordHash !== '') {
            $users[$username] = [
                'password_hash' => $passwordHash,
                'role' => $username === 'admin' ? 'full' : normalize_admin_role((string) (is_array($user) ? ($user['role'] ?? 'download') : 'download')),
            ];
        }
    }

    if (!isset($users['admin']) && !empty($config['admin_password_hash'])) {
        $users['admin'] = ['password_hash' => (string) $config['admin_password_hash'], 'role' => 'full'];
    }

    ksort($users, SORT_NATURAL | SORT_FLAG_CASE);
    if (isset($users['admin'])) {
        $admin = $users['admin'];
        unset($users['admin']);
        $users = ['admin' => $admin] + $users;
    }
    return $users;
}

function uuid_v4(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    $hex = bin2hex($data);
    return sprintf('%s-%s-%s-%s-%s',
        substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4),
        substr($hex, 16, 4), substr($hex, 20, 12)
    );
}

function random_password(int $length = 14): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $out;
}

function app_strlen(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
}

function app_substr(string $value, int $start, int $length): string
{
    return function_exists('mb_substr') ? mb_substr($value, $start, $length) : substr($value, $start, $length);
}

function app_strtolower(string $value): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
}

function app_strtoupper(string $value): string
{
    return function_exists('mb_strtoupper') ? mb_strtoupper($value) : strtoupper($value);
}

function secure_password_hash(string $password): string
{
    $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    $hash = password_hash($password, $algorithm);
    if ($hash === false) {
        throw new RuntimeException('The password could not be securely hashed.');
    }
    return $hash;
}

function encrypt_admin_value(array $config, string $plain): string
{
    $secret = (string) ($config['app_secret'] ?? '');
    if ($secret === '' || !extension_loaded('openssl')) {
        throw new RuntimeException('The encryption key or OpenSSL extension is missing.');
    }
    $key = hash('sha256', $secret, true);
    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'downloads-gallery-password');
    if ($ciphertext === false || strlen($tag) !== 16) {
        throw new RuntimeException('The gallery password could not be encrypted.');
    }
    return 'v1:' . base64_encode($iv . $tag . $ciphertext);
}

function decrypt_admin_value(array $config, string $payload): ?string
{
    if (!str_starts_with($payload, 'v1:')) {
        return null;
    }
    $raw = base64_decode(substr($payload, 3), true);
    if ($raw === false || strlen($raw) < 29) {
        return null;
    }
    $secret = (string) ($config['app_secret'] ?? '');
    if ($secret === '' || !extension_loaded('openssl')) {
        return null;
    }
    $key = hash('sha256', $secret, true);
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ciphertext = substr($raw, 28);
    $plain = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'downloads-gallery-password');
    return is_string($plain) ? $plain : null;
}

function gallery_admin_password(array $config, array $gallery): ?string
{
    $encrypted = (string) ($gallery['password_admin'] ?? '');
    return $encrypted !== '' ? decrypt_admin_value($config, $encrypted) : null;
}

function normalize_filename(string $name): string
{
    $name = basename(str_replace('\\', '/', $name));
    $name = preg_replace('/[^\pL\pN._ -]+/u', '_', $name) ?: 'photo';
    return app_substr($name, 0, 180);
}

function format_bytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $value = (float) $bytes;
    $i = 0;
    while ($value >= 1024 && $i < count($units) - 1) {
        $value /= 1024;
        $i++;
    }
    return number_format($value, $i === 0 ? 0 : 1, ',', ' ') . ' ' . $units[$i];
}

function gallery_session_key(string $uuid): string
{
    return 'gallery_access_' . hash('sha256', $uuid);
}

function gallery_is_unlocked(array $config, array $gallery): bool
{
    $uuid = (string) ($gallery['id'] ?? '');
    if ($uuid === '') {
        return false;
    }

    $entry = $_SESSION[gallery_session_key($uuid)] ?? null;
    if ($entry === null) {
        return false;
    }

    $expectedVersion = gallery_auth_version($gallery);
    $ttl = max(300, (int) ($config['gallery_unlock_ttl'] ?? 86400));

    // Backward compatibility with old int-only session payload.
    if (is_int($entry)) {
        $entry = ['unlocked_at' => $entry, 'auth_version' => $expectedVersion];
    }

    if (!is_array($entry)) {
        unset($_SESSION[gallery_session_key($uuid)]);
        return false;
    }

    $unlockedAt = (int) ($entry['unlocked_at'] ?? 0);
    $sessionVersion = (int) ($entry['auth_version'] ?? 0);
    if ($unlockedAt <= 0 || $sessionVersion !== $expectedVersion) {
        unset($_SESSION[gallery_session_key($uuid)]);
        return false;
    }

    if (($unlockedAt + $ttl) < time()) {
        unset($_SESSION[gallery_session_key($uuid)]);
        return false;
    }

    return true;
}

function set_gallery_unlocked(array $gallery): void
{
    $uuid = (string) ($gallery['id'] ?? '');
    if ($uuid === '') {
        return;
    }
    $_SESSION[gallery_session_key($uuid)] = [
        'unlocked_at' => time(),
        'auth_version' => gallery_auth_version($gallery),
    ];
}

function aperture_icon(int $size = 28, string $class = ''): string
{
    $cls = $class !== '' ? ' class="' . e($class) . '"' : '';
    return '<svg' . $cls . ' width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9.3"></circle><path d="M12 20 5.07 8 18.93 8Z"></path><path d="M18.93 16 5.07 16 12 4Z"></path></svg>';
}

function safe_download_name(string $name): string
{
    $name = str_replace(["\r", "\n", '"'], ['', '', "'"], $name);
    return $name !== '' ? $name : 'download';
}

function format_last_access(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return 'never';
    }

    try {
        $dt = new DateTimeImmutable($value);
    } catch (Throwable) {
        return 'unknown';
    }

    $today = new DateTimeImmutable('today');
    $yesterday = $today->modify('-1 day');
    $day = $dt->format('Y-m-d');

    if ($day === $today->format('Y-m-d')) {
        return 'today';
    }
    if ($day === $yesterday->format('Y-m-d')) {
        return 'yesterday';
    }

    return $dt->format('d.m.Y');
}

function format_retry_after(int $seconds): string
{
    $seconds = max(1, $seconds);
    if ($seconds < 60) {
        return $seconds . ' second' . ($seconds === 1 ? '' : 's');
    }
    $minutes = (int) ceil($seconds / 60);
    return $minutes . ' minute' . ($minutes === 1 ? '' : 's');
}

function send_file_response(string $path, string $mime, string $disposition, string $downloadName, bool $allowRanges = true): void
{
    if (!is_file($path)) {
        http_response_code(404);
        exit;
    }

    $size = (int) (filesize($path) ?: 0);
    $start = 0;
    $end = $size > 0 ? $size - 1 : 0;
    $status = 200;

    if ($allowRanges) {
        header('Accept-Ranges: bytes');
        $rangeHeader = (string) ($_SERVER['HTTP_RANGE'] ?? '');
        if ($rangeHeader !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', trim($rangeHeader), $m)) {
            if ($m[1] === '' && $m[2] === '') {
                http_response_code(416);
                header('Content-Range: bytes */' . $size);
                exit;
            }

            if ($m[1] === '') {
                $suffix = (int) $m[2];
                if ($suffix <= 0) {
                    http_response_code(416);
                    header('Content-Range: bytes */' . $size);
                    exit;
                }
                $start = max(0, $size - $suffix);
            } else {
                $start = (int) $m[1];
            }

            if ($m[2] !== '') {
                $end = (int) $m[2];
            }

            if ($size === 0 || $start > $end || $start >= $size) {
                http_response_code(416);
                header('Content-Range: bytes */' . $size);
                exit;
            }

            $end = min($end, $size - 1);
            $status = 206;
            header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
        }
    }

    $length = max(0, ($end - $start) + 1);
    http_response_code($status);
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . $length);
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: ' . $disposition . '; filename="' . safe_download_name($downloadName) . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));

    $fp = fopen($path, 'rb');
    if ($fp === false) {
        http_response_code(500);
        exit;
    }

    try {
        if ($start > 0) {
            fseek($fp, $start);
        }
        $remaining = $length;
        $chunk = 8192 * 16;
        while ($remaining > 0 && !feof($fp)) {
            $read = (int) min($chunk, $remaining);
            $buffer = fread($fp, $read);
            if ($buffer === false || $buffer === '') {
                break;
            }
            echo $buffer;
            $remaining -= strlen($buffer);
            if (connection_aborted()) {
                break;
            }
        }
    } finally {
        fclose($fp);
    }
    exit;
}
