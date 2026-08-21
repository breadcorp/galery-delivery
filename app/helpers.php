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

function require_admin(array $config): void
{
    if (!is_admin()) {
        redirect_to($config, 'admin');
    }
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

function gallery_is_unlocked(string $uuid): bool
{
    return !empty($_SESSION[gallery_session_key($uuid)]);
}

function set_gallery_unlocked(string $uuid): void
{
    $_SESSION[gallery_session_key($uuid)] = time();
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
