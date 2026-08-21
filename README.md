# Gallery Delivery

Self-hosted photo gallery delivery for photographers.

Gallery Delivery is a standalone PHP app for private client galleries with password protection, previews, and ZIP downloads. It is designed for simple file upload deployments.

## Project history

Gallery Delivery originally started as a small personal project built for my own photo delivery workflow. It went through several private iterations before being cleaned up and published as an open-source project. Public releases therefore start in the 0.x version range.

## Release

Current public release: `0.2.5`

## Core goals

- PHP 8.2+
- no database
- no framework
- no Composer required for normal usage
- Apache and Nginx support
- upload-and-run deployment

## Features

- private password-protected galleries
- per-gallery UUID links
- optional gallery names
- per-photo downloads
- full gallery ZIP download
- lazy ZIP rebuild (rebuild only when needed)
- JPG, PNG, WebP uploads
- generated JPG previews (GD optional)
- encrypted admin-visible gallery passwords
- gallery enable/disable
- gallery password rotation
- auth-version invalidation on password change
- gallery unlock session TTL
- file-based persistent rate limiting (admin + gallery unlock)
- multi-disk storage support
- cross-disk gallery lookup by UUID
- duplicate UUID conflict detection across disks
- cached storage stats for dashboard performance
- lightweight sidecar live stats
- global background (file or URL)
- per-gallery custom background URL
- Apache/Nginx hardening examples

## Installation

### 1. Upload files

Upload the `downloads` directory to your web root, for example:

```text
/var/www/html/downloads/
```

### 2. Configure storage

Set storage via `config.php` and/or environment variables.

Default:

```text
/mnt/ssd/photos/downloads
```

The storage path must be writable by PHP.

### 3. Open setup

Open:

```text
https://your-domain.com/downloads/
```

Complete the setup form to initialize admin access.

## Routing

Default mount:

```text
/downloads/
```

Main routes:

```text
/downloads/admin
/downloads/<gallery-uuid>
/downloads/<gallery-uuid>/photo/<photo-id>
/downloads/<gallery-uuid>/full.zip
```

## Requirements

- PHP 8.2+
- writable storage directory
- Apache or Nginx

Required PHP extensions:

- `fileinfo`
- `openssl`

Recommended:

- `zip`
- `gd`
- `mbstring`

## Multi-disk behavior

The active storage disk determines where **new** galleries are created.

Existing galleries remain accessible by UUID even after changing the active disk.

Dashboard listing scans configured storage disks and can detect duplicate UUID conflicts.

## ZIP behavior

ZIP files are not rebuilt after every upload/delete.

Flow:

```text
photos changed -> ZIP marked outdated
Download all -> ZIP rebuilt if needed, then downloaded
```

ZIP build uses lock files and atomic rename to avoid partial archives.

## Download behavior

- Photo downloads can use client-side progress UI.
- Large ZIP downloads are kept browser-streamed (no full ZIP Blob buffering in JS memory).
- Server-side download authorization remains enforced.
- HTTP Range requests are supported in PHP download responses.

## Security notes

- Keep gallery storage outside public web root.
- Do not expose storage via direct web alias/symlink.
- Keep `app/` and `deploy/` blocked from direct HTTP access.
- Keep internal lock/tmp/metadata files inaccessible via web server.

See:

- `.htaccess`
- `deploy/nginx.conf.example`

## Backward compatibility

`gallery.json` from existing installations is loaded and normalized with defaults where possible.

New fields are added naturally during normal write operations. Existing UUID links remain unchanged.

## Backup

Back up both:

- application files
- storage directory (photos + metadata)

## License

GPL-3.0
