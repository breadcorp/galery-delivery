<?php
declare(strict_types=1);

return [
    // Physical SSD storage. Keep this outside the public web directory.
    'storage_root' => getenv('DOWNLOADS_STORAGE_ROOT') ?: '/mnt/ssd/photos/downloads',

    // URL prefix where this app is mounted.
    'base_path' => rtrim(getenv('DOWNLOADS_BASE_PATH') ?: '/downloads', '/'),

    'app_name' => 'Downloads',

    // Filled automatically by the one-time introduction.
    'setup_complete' => false,
    'admin_password_hash' => getenv('DOWNLOADS_ADMIN_PASSWORD_HASH') ?: '',

    // Generated automatically during setup and used to encrypt recoverable gallery passwords.
    'app_secret' => getenv('DOWNLOADS_APP_SECRET') ?: '',

    // Maximum size of one uploaded photo: 200 MB.
    'max_photo_bytes' => 200 * 1024 * 1024,

    // Maximum background upload: 250 MB.
    'max_background_bytes' => 250 * 1024 * 1024,

    'session_name' => 'downloads_admin_session',
    'timezone' => getenv('APP_TIMEZONE') ?: 'Europe/Prague',
];
