<?php
declare(strict_types=1);
// Local development only: php -S 127.0.0.1:8785 -t . deploy/dev-router.php
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }
$root = dirname(__DIR__);
$devConfig = require $root . '/config.php';
if (is_file($root . '/config.local.php')) $devConfig = array_replace($devConfig, require $root . '/config.local.php');
$base = (string) $devConfig['base_path'];
$requestPath = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if ($base !== '' && $requestPath !== $base && !str_starts_with($requestPath, $base . '/')) { http_response_code(404); exit; }
$relative = substr($requestPath, strlen($base));
if (preg_match('#^/assets/[a-zA-Z0-9_/-]+\.(css|js|png|jpe?g|webp|gif|svg|ico|woff2?)$#D', $relative, $match)) {
    $file = $root . $relative;
    if (!is_file($file)) { http_response_code(404); exit; }
    $mime = match ($match[1]) { 'css' => 'text/css', 'js' => 'application/javascript', default => (new finfo(FILEINFO_MIME_TYPE))->file($file) };
    header('Content-Type: ' . $mime); header('X-Content-Type-Options: nosniff'); readfile($file); exit;
}
if (preg_match('#(?:^|/)\.|^/(?:app|deploy|tests)(?:/|$)#', $relative) || (is_file($root . $relative) && $relative !== '/index.php')) { http_response_code(403); exit; }
$_SERVER['SCRIPT_NAME'] = $base . '/index.php';
require $root . '/index.php';
