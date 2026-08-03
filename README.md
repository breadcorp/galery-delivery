# **Deliver pictures to your customer in style**


# Private gallery `/downloads/`

A standalone PHP application ready to upload to a server.

## Installation without terminal access

1. Upload the entire `downloads` folder to the web root so it is available as `/downloads/`.
2. Open `https://your-domain.com/downloads/` in your browser.
3. The initial page checks the server. Enter the admin password twice and complete the setup.

Once the password is saved, the initial setup is permanently locked and the app opens the admin area at `/downloads/admin`.

## Prepared routes

- admin dashboard: `/downloads/admin`
- public gallery: `/downloads/<uuid-gallery>`
- physical data: `/mnt/ssd/photos/downloads/<uuid-gallery>`

## Highlights in version 4

- Global background can be configured with a direct HTTPS link to a GIF.
- Each gallery can override the global background with its own HTTPS GIF link or return to the global one with one click.
- The password of each newly created or changed gallery remains permanently visible in the admin area.
- Recoverable passwords are stored encrypted on the SSD; visitor authentication still uses a secure hash.

## Features

- each gallery has its own password
- photos can be downloaded individually
- automatic `full.zip`
- JPG, PNG, and WebP up to 200 MB per file
- photo previews
- GIF, JPG, PNG, WebP, MP4, or WebM as background
- activate, deactivate, and delete galleries
- metadata in `gallery.json`; for authentication, the password is stored as a hash and for admins also in encrypted form
- configurable storage disks and active storage location from the admin settings

## Server requirements

- PHP 8.2 or newer
- required extensions: `fileinfo` and `openssl`
- recommended extensions: `zip`, `gd`, and `mbstring`
- the web application must be able to create and write to `/mnt/ssd/photos/downloads`
- Apache with `mod_rewrite` and enabled `.htaccess`, or equivalent Nginx configuration

The `.user.ini` file will try to set uploads to 200 MB. Some servers ignore custom `.user.ini` files; the actual limits can be viewed on the initial setup page.

## Important

`/mnt/ssd/photos/downloads` must not be exposed as a public alias or symlink of the web server. Photos are served only through the application after password verification.

## Upgrade from version 2 or 3

1. Leave the entire contents of `/mnt/ssd/photos/downloads/` unchanged.
2. Replace only the web application files in `/var/www/html/downloads/`.
3. On first load, the encryption key is created automatically on the SSD.
4. For older galleries, the original password cannot be recovered from the hash. Just change it once; the new password will remain visible in the admin area.
