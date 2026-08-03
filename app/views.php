<?php
declare(strict_types=1);

function render_header(array $config, string $title, bool $admin = false, string $bodyClass = ''): void
{
    $base = e((string) $config['base_path']);
    $title = e($title);
    echo <<<HTML
<!doctype html>
<html lang="cs">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow,noarchive">
<title>{$title}</title>
<link rel="stylesheet" href="{$base}/assets/modern.css?v=4">
</head>
<body class="{$bodyClass}">
HTML;
    if ($admin && is_admin()) {
        $brand = e((string) ($config['app_name'] ?? 'Downloads'));
        echo '<header class="admin-nav"><a class="brand" href="' . $base . '/admin">' . $brand . '</a><nav>';
        echo '<a href="' . $base . '/admin">Galleries</a><a href="' . $base . '/admin/background">Background</a><a href="' . $base . '/admin/settings">Settings</a>';
        echo '<form method="post" action="' . $base . '/admin/logout"><input type="hidden" name="csrf" value="' . e(csrf_token()) . '"><button class="link-button">Sign out</button></form>';
        echo '</nav></header>';
    }
}

function render_flashes(): void
{
    foreach (consume_flashes() as $item) {
        echo '<div class="flash ' . e((string) ($item['type'] ?? 'info')) . '">' . e((string) ($item['message'] ?? '')) . '</div>';
    }
}

function render_footer(array $config): void
{
    $base = e((string) $config['base_path']);
    echo '<script src="' . $base . '/assets/app.js?v=2"></script></body></html>';
}

function render_background(array $config, ?array $gallery = null): void
{
    $background = effective_background($config, $gallery);
    if (!$background) {
        echo '<div class="background fallback"></div>';
        echo '<div class="background-overlay"></div>';
        return;
    }
    if (($background['source'] ?? '') === 'url' && !empty($background['url'])) {
        echo '<img class="background" src="' . e((string) $background['url']) . '" alt="" referrerpolicy="no-referrer">';
        echo '<div class="background-overlay"></div>';
        return;
    }
    $src = e(base_url($config, '_background'));
    if (($background['type'] ?? '') === 'video') {
        echo '<video class="background" autoplay muted loop playsinline preload="metadata"><source src="' . $src . '" type="' . e((string) $background['mime']) . '"></video>';
    } else {
        echo '<img class="background" src="' . $src . '" alt="">';
    }
    echo '<div class="background-overlay"></div>';
}
