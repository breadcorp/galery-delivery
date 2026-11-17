<?php
declare(strict_types=1);

return [
    // Stable private control directory; independent of the selected photo disk.
    'app_data_dir' => getenv('DOWNLOADS_APP_DATA_DIR') ?: '',
    // php (portable), x-accel (Nginx), or x-sendfile (Apache).
    'download_backend' => getenv('DOWNLOADS_DOWNLOAD_BACKEND') ?: 'php',
    // Physical directory => internal Nginx URI, configured per storage disk.
    'x_accel_mappings' => [],
    // Physical SSD storage. Keep this outside the public web directory.
    'storage_root' => getenv('DOWNLOADS_STORAGE_ROOT') ?: '/mnt/ssd/photos/downloads',

    // URL prefix where this app is mounted.
    'base_path' => rtrim(getenv('DOWNLOADS_BASE_PATH') ?: '/downloads', '/'),

    // Optional canonical public origin, e.g. https://photos.example.com.
    'public_origin' => getenv('DOWNLOADS_PUBLIC_ORIGIN') ?: '',

    'app_name' => 'Downloads',

    // Filled automatically by the one-time introduction.
    'setup_complete' => false,
    'admin_password_hash' => getenv('DOWNLOADS_ADMIN_PASSWORD_HASH') ?: '',
    'admin_users' => [],

    // Generated automatically during setup and used to encrypt recoverable gallery passwords.
    'app_secret' => getenv('DOWNLOADS_APP_SECRET') ?: '',

    // Maximum size of one uploaded photo: 200 MB.
    'max_photo_bytes' => 200 * 1024 * 1024,

    // Maximum background upload: 250 MB.
    'max_background_bytes' => 250 * 1024 * 1024,

    // Gallery unlock session TTL: 24 hours.
    'gallery_unlock_ttl' => 24 * 60 * 60,

    'session_name' => 'downloads_admin_session',
    'timezone' => getenv('APP_TIMEZONE') ?: 'Europe/Prague',
];
